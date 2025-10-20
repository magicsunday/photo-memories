<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use DateTimeImmutable;
use DateTimeInterface;

use function array_key_exists;
use function is_float;
use function is_int;
use function is_string;
use function trim;

/**
 * Describes hidden persons, pets, places and dates that should be excluded from the feed.
 */
final class FeedVisibilityFilter
{
    /**
     * @var array<string, true>
     */
    private array $hiddenPersons;

    /**
     * @var array<string, true>
     */
    private array $hiddenPets;

    /**
     * @var array<string, true>
     */
    private array $hiddenPlaces;

    /**
     * @var array<string, true>
     */
    private array $hiddenDates;

    /**
     * @param list<string> $hiddenPersons
     * @param list<string> $hiddenPets
     * @param list<string> $hiddenPlaces
     * @param list<string> $hiddenDates
     */
    public function __construct(
        array $hiddenPersons = [],
        array $hiddenPets = [],
        array $hiddenPlaces = [],
        array $hiddenDates = [],
    ) {
        $this->hiddenPersons = $this->normaliseMap($hiddenPersons);
        $this->hiddenPets    = $this->normaliseMap($hiddenPets);
        $this->hiddenPlaces  = $this->normaliseMap($hiddenPlaces);
        $this->hiddenDates   = $this->normaliseMap($hiddenDates);
    }

    public function isEmpty(): bool
    {
        return $this->hiddenPersons === []
            && $this->hiddenPets === []
            && $this->hiddenPlaces === []
            && $this->hiddenDates === [];
    }

    public function hasHiddenPersons(): bool
    {
        return $this->hiddenPersons !== [];
    }

    public function hasHiddenPets(): bool
    {
        return $this->hiddenPets !== [];
    }

    public function hasHiddenPlaces(): bool
    {
        return $this->hiddenPlaces !== [];
    }

    public function hasHiddenDates(): bool
    {
        return $this->hiddenDates !== [];
    }

    public function intersectsPersons(iterable $persons): bool
    {
        if ($this->hiddenPersons === []) {
            return false;
        }

        foreach ($persons as $person) {
            $normalized = $this->normaliseScalar($person);
            if ($normalized !== null && array_key_exists($normalized, $this->hiddenPersons)) {
                return true;
            }
        }

        return false;
    }

    public function intersectsPets(iterable $pets): bool
    {
        if ($this->hiddenPets === []) {
            return false;
        }

        foreach ($pets as $pet) {
            $normalized = $this->normaliseScalar($pet);
            if ($normalized !== null && array_key_exists($normalized, $this->hiddenPets)) {
                return true;
            }
        }

        return false;
    }

    public function isPlaceHidden(?string $place): bool
    {
        if ($place === null || $this->hiddenPlaces === []) {
            return false;
        }

        $normalized = $this->normaliseText($place);
        if ($normalized === null) {
            return false;
        }

        return array_key_exists($normalized, $this->hiddenPlaces);
    }

    public function intersectsDates(iterable $dates): bool
    {
        if ($this->hiddenDates === []) {
            return false;
        }

        foreach ($dates as $date) {
            $normalized = $this->normaliseDate($date);
            if ($normalized !== null && array_key_exists($normalized, $this->hiddenDates)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, true>
     */
    private function normaliseMap(array $values): array
    {
        $map = [];

        foreach ($values as $value) {
            $normalized = $this->normaliseText($value);
            if ($normalized === null) {
                continue;
            }

            $map[$normalized] = true;
        }

        return $map;
    }

    private function normaliseScalar(mixed $value): ?string
    {
        if (is_string($value) || $value instanceof \Stringable) {
            return $this->normaliseText((string) $value);
        }

        if (is_int($value) || is_float($value)) {
            return $this->normaliseText((string) $value);
        }

        return null;
    }

    private function normaliseText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normaliseDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = $this->normaliseScalar($value);
        if ($text === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $text);
        if ($date === false) {
            return null;
        }

        return $date->format('Y-m-d');
    }
}
