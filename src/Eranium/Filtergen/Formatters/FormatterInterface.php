<?php
/**
 * Eranium Filtergen
 * @copyright Eranium B.V.
 * @license Mozilla Public License 2.0
 * @link https://github.com/eranium/filtergen
 */

declare(strict_types=1);

namespace Eranium\Filtergen\Formatters;

interface FormatterInterface
{
    /**
     * Implement this in your custom prefix list extension.
     * @param  array  $prefixes
     * @param  array  $options
     * @return string
     */
    public static function format(array $prefixes, array $options = []): string;
}
