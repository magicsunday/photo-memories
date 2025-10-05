<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use function array_map;
use function count;
use function max;
use function number_format;
use function preg_quote;
use function sprintf;
use function str_replace;

/**
 * Class DefaultOverpassQueryBuilder.
 */
final readonly class DefaultOverpassQueryBuilder implements OverpassQueryBuilderInterface
{
    public function __construct(
        private OverpassTagConfiguration $configuration,
        private int $queryTimeout = 25,
    ) {
    }

    public function build(float $lat, float $lon, int $radius, ?int $limit): string
    {
        $latS   = number_format($lat, 7, '.', '');
        $lonS   = number_format($lon, 7, '.', '');
        $radius = max(1, $radius);

        $query = sprintf('[out:json][timeout:%d];(', $this->queryTimeout);
        foreach ($this->configuration->getAllowedTagCombinations() as $combination) {
            if ($combination === []) {
                continue;
            }

            $query .= sprintf('nwr(around:%d,%s,%s)', $radius, $latS, $lonS);

            foreach ($combination as $key => $values) {
                if ($values === []) {
                    continue;
                }

                if (count($values) === 1) {
                    $query .= sprintf('["%s"="%s"]', $key, str_replace('"', '\\"', $values[0]));

                    continue;
                }

                $escaped = array_map(static fn (string $value): string => preg_quote($value, '/'), $values);
                $pattern = implode('|', $escaped);
                $query .= sprintf('["%s"~"^(%s)$"]', $key, $pattern);
            }

            $query .= ';';
        }

        $limitFragment = $limit !== null ? ' ' . max(1, $limit) : '';

        return $query . sprintf(');out tags center%s;', $limitFragment);
    }
}
