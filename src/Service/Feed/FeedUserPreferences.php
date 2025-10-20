<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use function in_array;

/**
 * Immutable snapshot of the stored user feed preferences.
 */
final class FeedUserPreferences
{
    /**
     * @param list<string> $favourites
     * @param list<string> $hiddenAlgorithms
     * @param list<string> $hiddenPersons
     * @param list<string> $hiddenPets
     * @param list<string> $hiddenPlaces
     * @param list<string> $hiddenDates
     * @param list<string> $favouritePersons
     * @param list<string> $favouritePlaces
     */
    public function __construct(
        private readonly string $userId,
        private readonly string $profileKey,
        private readonly array $favourites,
        private readonly array $hiddenAlgorithms,
        private readonly array $hiddenPersons = [],
        private readonly array $hiddenPets = [],
        private readonly array $hiddenPlaces = [],
        private readonly array $hiddenDates = [],
        private readonly array $favouritePersons = [],
        private readonly array $favouritePlaces = [],
    ) {
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getProfileKey(): string
    {
        return $this->profileKey;
    }

    /**
     * @return list<string>
     */
    public function getFavourites(): array
    {
        return $this->favourites;
    }

    /**
     * @return list<string>
     */
    public function getHiddenAlgorithms(): array
    {
        return $this->hiddenAlgorithms;
    }

    /**
     * @return list<string>
     */
    public function getHiddenPersons(): array
    {
        return $this->hiddenPersons;
    }

    /**
     * @return list<string>
     */
    public function getHiddenPets(): array
    {
        return $this->hiddenPets;
    }

    /**
     * @return list<string>
     */
    public function getHiddenPlaces(): array
    {
        return $this->hiddenPlaces;
    }

    /**
     * @return list<string>
     */
    public function getHiddenDates(): array
    {
        return $this->hiddenDates;
    }

    /**
     * @return list<string>
     */
    public function getFavouritePersons(): array
    {
        return $this->favouritePersons;
    }

    /**
     * @return list<string>
     */
    public function getFavouritePlaces(): array
    {
        return $this->favouritePlaces;
    }

    public function isFavourite(string $itemId): bool
    {
        return in_array($itemId, $this->favourites, true);
    }

    public function isAlgorithmOptedOut(string $algorithm): bool
    {
        return in_array($algorithm, $this->hiddenAlgorithms, true);
    }

    /**
     * @param list<string>      $favourites
     * @param list<string>      $hiddenAlgorithms
     * @param list<string>|null $hiddenPersons
     * @param list<string>|null $hiddenPets
     * @param list<string>|null $hiddenPlaces
     * @param list<string>|null $hiddenDates
     * @param list<string>|null $favouritePersons
     * @param list<string>|null $favouritePlaces
     */
    public function withLists(
        array $favourites,
        array $hiddenAlgorithms,
        ?array $hiddenPersons = null,
        ?array $hiddenPets = null,
        ?array $hiddenPlaces = null,
        ?array $hiddenDates = null,
        ?array $favouritePersons = null,
        ?array $favouritePlaces = null,
    ): self {
        return new self(
            $this->userId,
            $this->profileKey,
            $favourites,
            $hiddenAlgorithms,
            $hiddenPersons ?? $this->hiddenPersons,
            $hiddenPets ?? $this->hiddenPets,
            $hiddenPlaces ?? $this->hiddenPlaces,
            $hiddenDates ?? $this->hiddenDates,
            $favouritePersons ?? $this->favouritePersons,
            $favouritePlaces ?? $this->favouritePlaces,
        );
    }
}
