<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\Contract\OverpassPrimaryTagResolverInterface;

use function array_any;
use function is_string;

/**
 * Class OverpassPrimaryTagResolver
 */
final readonly class OverpassPrimaryTagResolver implements OverpassPrimaryTagResolverInterface
{
    public function __construct(private OverpassTagConfiguration $configuration)
    {
    }

    public function resolve(array $tags): ?array
    {
        foreach ($this->configuration->getAllowedTagMap() as $key => $values) {
            $value = $this->stringOrNull($tags[$key] ?? null);
            if ($value === null) {
                continue;
            }

            if ($this->isAllowedTagValue($value, $values)) {
                return ['key' => $key, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * @param list<string> $allowedValues
     */
    private function isAllowedTagValue(string $value, array $allowedValues): bool
    {
        if ($allowedValues === []) {
            return false;
        }

        return array_any(
            $allowedValues,
            static fn (string $allowed): bool => $allowed === $value
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
