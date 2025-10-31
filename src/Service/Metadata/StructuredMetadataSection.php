<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use function array_key_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Immutable value map for a structured metadata section.
 *
 * @phpstan-type SectionScalar bool|int|float|string|null
 * @phpstan-type SectionValue SectionScalar|list<SectionScalar>
 */
final readonly class StructuredMetadataSection
{
    /**
     * @param array<string, SectionValue> $values
     */
    private function __construct(private array $values)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, bool|int|float|string|null|array|object>|null $values
     */
    public static function fromArray(?array $values): self
    {
        if ($values === null) {
            return self::empty();
        }

        return new self(self::normalise($values));
    }

    /**
     * @return array<string, SectionValue>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return SectionValue|null
     */
    public function __get(string $key): array|bool|float|int|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @param array<string, bool|int|float|string|null|array|object> $values
     *
     * @return array<string, SectionValue>
     */
    private static function normalise(array $values): array
    {
        $normalised = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (self::isScalar($value)) {
                $normalised[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $list = [];
                foreach ($value as $entry) {
                    if (self::isScalar($entry)) {
                        $list[] = $entry;
                    }
                }

                if ($list !== []) {
                    $normalised[$key] = $list;
                }
            }
        }

        return $normalised;
    }

    private static function isScalar(array|bool|float|int|string|null|object $value): bool
    {
        return $value === null
            || is_bool($value)
            || is_int($value)
            || is_float($value)
            || is_string($value);
    }
}
