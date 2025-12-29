<?php
/**
 * Eranium Filtergen
 * @copyright Eranium B.V.
 * @license Mozilla Public License 2.0
 * @link https://github.com/eranium/filtergen
 * @note This file is a demo of the IRRd client (php client.php).
 */

declare(strict_types=1);

use Eranium\Filtergen\IRRDClient;

require_once __DIR__.'/src/Eranium/Filtergen/Filtergen.php';
require_once __DIR__.'/src/Eranium/Filtergen/IRRDClient.php';

try {
    // Create a new IRRDClient object;
    $newClient = (new IRRDClient())->connect();

    // Get version info from IRRd server;
    $output = $newClient->command('!v')->read();
    print_r($output);

    // Get all information about the sources from the IRRd server, calling on same object reconnects to IRRd server;
    $output = $newClient->command('!J-*')->read();
    print_r($output);

    // You would probably use this the most, chained commands will be executed in the same connection;
    $chainedOutput = $newClient
        ->command('!v') // get version;
        ->command('!jARIN') // get ARIN source info;
        ->command('!sRIPE,RPKI') // only use RIPE and RPKI sources;
        ->command('!gAS65000') // get prefixes from AS only;
        ->read();
    print_r($chainedOutput);
} catch (\Exception $e) {
    die($e->getMessage());
}
