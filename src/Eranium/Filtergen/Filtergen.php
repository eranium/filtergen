<?php
/**
 * Eranium Filtergen
 * @copyright Eranium B.V.
 * @license Mozilla Public License 2.0
 * @link https://github.com/eranium/filtergen
 */

declare(strict_types=1);

namespace Eranium\Filtergen;

class Filtergen
{
    /**
     * @var IRRDClient
     */
    private IRRDClient $client;

    /**
     * @param  IRRDClient  $IRRDClient
     * @throws \Exception
     */
    public function __construct(IRRDClient $IRRDClient)
    {
        $this->client = $IRRDClient->connect();
    }

    /**
     * @param  array  $sources
     * @return void
     * @throws \Exception
     */
    private function isSupportedSource(array $sources): void
    {
        $supportedSources = ['NTTCOM', 'INTERNAL', 'LACNIC', 'RADB', 'RIPE', 'RIPE-NONAUTH', 'ALTDB', 'BELL', 'LEVEL3', 'APNIC', 'JPIRR', 'ARIN', 'BBOI', 'TC', 'AFRINIC', 'IDNIC', 'RPKI', 'REGISTROBR', 'CANARIE'];
        if (!empty(array_diff($sources, $supportedSources))) {
            throw new \Exception(
                'Invalid source provided.'
            );
        }
    }

    /**
     * @param  string  $jsonSourceStatus
     * @param  array  $sources
     * @return array
     */
    private function processSourceStatus(string $jsonSourceStatus, array $sources): array
    {
        $sourcesTimestamps = json_decode($jsonSourceStatus, true);
        return array_intersect_key(
            array_map(fn($v) => $v['last_update'] ?? null, $sourcesTimestamps),
            array_flip($sources)
        );
    }

    /**
     * @param  string  $asnOrSet
     * @param  int  $ipType
     * @return string|void
     * @throws \Exception
     */
    private function prefixCommand(string $asnOrSet, int $ipType = 4)
    {
        if (preg_match('/^(?:AS)?(\d+)$/i', $asnOrSet, $asnWithoutAs)) {
            if (!isset($asnWithoutAs[1])) {
                throw new \Exception('Could not get ASN.');
            }
            if ($ipType == 4) {
                return '!gAS'.$asnWithoutAs[1];
            }
            if ($ipType == 6) {
                return '!6AS'.$asnWithoutAs[1];
            }
        } else {
            if (preg_match('/^AS(?:\d+)?(?:[:\-][A-Z0-9\-]+)+$/i', $asnOrSet)) {
                if ($ipType == 4) {
                    return '!a4'.$asnOrSet;
                }
                if ($ipType == 6) {
                    return '!a6'.$asnOrSet;
                }
            } else {
                throw new \Exception('Invalid ASN or AS-SET provided.');
            }
        }
    }

    /**
     * @param  array|false  $prefixes
     * @param  string  $vendor
     * @return string
     * @throws \Exception
     */
    public function formatToVendor(array|false $prefixes, string $vendor): string
    {
        if (empty($prefixes)) {
            return 'seq 1 deny 0.0.0.0/0 ';
        }
        if (!file_exists(__DIR__.'/Formatters/'.ucfirst(strtolower($vendor)).'Formatter.php')) {
            throw new \Exception('Could not load vendor.');
        }
        require_once __DIR__.'/Formatters/'.ucfirst(strtolower($vendor)).'Formatter.php';
        $class = '\Eranium\Filtergen\Formatters\\'.ucfirst($vendor).'Formatter';
        return $class::format($prefixes);
    }

    /**
     * @param  string  $asnOrSet
     * @param  array  $sources
     * @param  int  $ipType
     * @return array
     * @throws \Exception
     */
    public function getPrefixes(string $asnOrSet, array $sources = ['RIPE'], int $ipType = 4): array
    {
        $this->isSupportedSource($sources);
        $prefixCommand = $this->prefixCommand($asnOrSet, $ipType);
        $command = $this->client->command('!J-*')->command('!s'.implode(',', $sources))->command($prefixCommand)->read();
        if (!isset($command['!J-*'])) {
            throw new \Exception('Could not get latest status about sources.');
        }
        $lastUpdates = $this->processSourceStatus($command['!J-*'], $sources);
        if (isset($command[$prefixCommand]) && $command[$prefixCommand] !== false) {
            $prefixes = explode(' ', $command[$prefixCommand]);
            natsort($prefixes);
            $prefixes = array_values($prefixes);
        }
        return [
            'prefixes' => $prefixes ?? false,
            'sources' => $sources,
            'updated' => $lastUpdates
        ];
    }
}
