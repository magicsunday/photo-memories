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
     */
    public function __construct(
        private readonly string $userId,
        private readonly string $profileKey,
        private readonly array $favourites,
        private readonly array $hiddenAlgorithms,
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

    public function isFavourite(string $itemId): bool
    {
        return in_array($itemId, $this->favourites, true);
    }

    public function isAlgorithmOptedOut(string $algorithm): bool
    {
        return in_array($algorithm, $this->hiddenAlgorithms, true);
    }

    /**
     * @param list<string> $favourites
     * @param list<string> $hiddenAlgorithms
     */
    public function withLists(array $favourites, array $hiddenAlgorithms): self
    {
        return new self($this->userId, $this->profileKey, $favourites, $hiddenAlgorithms);
    }
}
