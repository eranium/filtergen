<?php
/**
 * Eranium Filtergen
 * @copyright Eranium B.V.
 * @license Mozilla Public License 2.0
 * @link https://github.com/eranium/filtergen
 * @note This file shows you how to use it in a web environment (api.php?set=AS65000&sources=RIPE,RPKI&type=4).
 */

declare(strict_types=1);

use Eranium\Filtergen\Filtergen;
use Eranium\Filtergen\IRRDClient;

require_once __DIR__.'/src/Eranium/Filtergen/Filtergen.php';
require_once __DIR__.'/src/Eranium/Filtergen/IRRDClient.php';

try {
    // Process GET params;
    if (!isset($_GET['set'])) {
        die('Missing set...');
    }
    $_GET['sources'] = $_GET['sources'] ?? 'RIPE,RPKI';
    $_GET['type'] = $_GET['type'] ?? 4;

    // Create new Filtergen object;
    $filterGen = new Filtergen(new IRRDClient());

    // Get prefixes based on input, validation is done here as well;
    $query = $filterGen->getPrefixes($_GET['set'], explode(',', $_GET['sources']), (int)$_GET['type']);

    // Output prefix list in Arista format;
    echo $filterGen->formatToVendor($query['prefixes'], 'Arista');
} catch (\Exception $e) {
    die($e->getMessage());
}
