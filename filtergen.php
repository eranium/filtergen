<?php
/**
 * Eranium Filtergen
 * @copyright Eranium B.V.
 * @license Mozilla Public License 2.0
 * @link https://github.com/eranium/filtergen
 * @note This file is an example how to use Filtergen from CLI (php filtergen.php AS65000 RIPE,RPKI).
 */

declare(strict_types=1);

use Eranium\Filtergen\Filtergen;
use Eranium\Filtergen\IRRDClient;

require_once __DIR__.'/src/Eranium/Filtergen/Filtergen.php';
require_once __DIR__.'/src/Eranium/Filtergen/IRRDClient.php';

try {
    // Create a new Filtergen object;
    $filterGen = new Filtergen(new IRRDClient());

    // Process args from CLI;
    $argv[2] = isset($argv[2]) ? explode(',', $argv[2]) : ['RIPE'];

    // Get prefixes based on args;
    $query = $filterGen->getPrefixes($argv[1], $argv[2], $argv[3] ?? 4);
    print_r($query);

    // Convert prefixes to an Arista styled prefix list;
    $vendored = $filterGen->formatToVendor($query['prefixes'], 'Arista');
    var_dump($vendored);
} catch (\Exception $e) {
    die($e->getMessage());
}
