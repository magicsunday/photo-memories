<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use MagicSunday\Memories\Service\Feed\FeedUserPreferenceStorage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function file_exists;
use function file_get_contents;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function uniqid;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class FeedUserPreferenceStorageTest extends TestCase
{
    #[Test]
    public function storesAndReadsPreferences(): void
    {
        $path = sys_get_temp_dir() . '/feed-pref-' . uniqid() . '.json';

        $storage = new FeedUserPreferenceStorage($path);

        $initial = $storage->getPreferences('alice', 'default');
        self::assertSame([], $initial->getFavourites());
        self::assertSame([], $initial->getHiddenAlgorithms());
        self::assertSame([], $initial->getBlockedAlgorithms());
        self::assertSame([], $initial->getHiddenPersons());
        self::assertSame([], $initial->getHiddenPets());
        self::assertSame([], $initial->getHiddenPlaces());
        self::assertSame([], $initial->getHiddenDates());
        self::assertSame([], $initial->getFavouritePersons());
        self::assertSame([], $initial->getFavouritePlaces());

        $storage->markFavourite('alice', 'default', 'cluster-1', true);
        $storage->markFavourite('alice', 'default', 'cluster-2', true);
        $storage->setAlgorithmOptOut('alice', 'default', 'holiday_event', true);
        $storage->setAlgorithmBlock('alice', 'default', 'video_story', true);
        $storage->setHiddenPersons('alice', 'default', ['bob', 'bob', 'carol']);
        $storage->setHiddenPets('alice', 'default', ['dog-1']);
        $storage->setHiddenPlaces('alice', 'default', ['berlin']);
        $storage->setHiddenDates('alice', 'default', ['2024-01-01']);
        $storage->setFavouritePersons('alice', 'default', ['dave']);
        $storage->setFavouritePlaces('alice', 'default', ['munich', 'berlin']);

        $updated = $storage->getPreferences('alice', 'default');
        self::assertSame(['cluster-1', 'cluster-2'], $updated->getFavourites());
        self::assertSame(['holiday_event'], $updated->getHiddenAlgorithms());
        self::assertSame(['video_story'], $updated->getBlockedAlgorithms());
        self::assertSame(['bob', 'carol'], $updated->getHiddenPersons());
        self::assertSame(['dog-1'], $updated->getHiddenPets());
        self::assertSame(['berlin'], $updated->getHiddenPlaces());
        self::assertSame(['2024-01-01'], $updated->getHiddenDates());
        self::assertSame(['dave'], $updated->getFavouritePersons());
        self::assertSame(['munich', 'berlin'], $updated->getFavouritePlaces());

        $storage->markFavourite('alice', 'default', 'cluster-1', false);
        $storage->setAlgorithmOptOut('alice', 'default', 'holiday_event', false);
        $storage->setAlgorithmBlock('alice', 'default', 'video_story', false);
        $storage->setHiddenPersons('alice', 'default', []);
        $storage->setHiddenPets('alice', 'default', []);
        $storage->setHiddenPlaces('alice', 'default', []);
        $storage->setHiddenDates('alice', 'default', []);
        $storage->setFavouritePersons('alice', 'default', []);
        $storage->setFavouritePlaces('alice', 'default', []);

        $final = $storage->getPreferences('alice', 'default');
        self::assertSame(['cluster-2'], $final->getFavourites());
        self::assertSame([], $final->getHiddenAlgorithms());
        self::assertSame([], $final->getBlockedAlgorithms());
        self::assertSame([], $final->getHiddenPersons());
        self::assertSame([], $final->getHiddenPets());
        self::assertSame([], $final->getHiddenPlaces());
        self::assertSame([], $final->getHiddenDates());
        self::assertSame([], $final->getFavouritePersons());
        self::assertSame([], $final->getFavouritePlaces());

        self::assertTrue(file_exists($path));
        @unlink($path);
    }

    #[Test]
    public function migratesLegacySchema(): void
    {
        $path = sys_get_temp_dir() . '/feed-pref-' . uniqid() . '.json';

        $legacy = [
            'users' => [
                'legacy-user' => [
                    'profiles' => [
                        'only' => [
                            'favourites' => ['cluster-1'],
                            'hidden_algorithms' => ['algo'],
                        ],
                    ],
                ],
            ],
        ];

        file_put_contents($path, json_encode($legacy, JSON_PRETTY_PRINT));

        $storage = new FeedUserPreferenceStorage($path);

        $preferences = $storage->getPreferences('legacy-user', 'only');

        self::assertSame(['cluster-1'], $preferences->getFavourites());
        self::assertSame(['algo'], $preferences->getHiddenAlgorithms());
        self::assertSame([], $preferences->getBlockedAlgorithms());
        self::assertSame([], $preferences->getHiddenPersons());
        self::assertSame([], $preferences->getHiddenPets());
        self::assertSame([], $preferences->getHiddenPlaces());
        self::assertSame([], $preferences->getHiddenDates());
        self::assertSame([], $preferences->getFavouritePersons());
        self::assertSame([], $preferences->getFavouritePlaces());

        $raw = file_get_contents($path);
        self::assertNotFalse($raw);

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('hidden_persons', $decoded['users']['legacy-user']['profiles']['only']);
        self::assertArrayHasKey('hidden_pets', $decoded['users']['legacy-user']['profiles']['only']);
        self::assertArrayHasKey('hidden_places', $decoded['users']['legacy-user']['profiles']['only']);
        self::assertArrayHasKey('hidden_dates', $decoded['users']['legacy-user']['profiles']['only']);
        self::assertArrayHasKey('favourite_persons', $decoded['users']['legacy-user']['profiles']['only']);
        self::assertArrayHasKey('favourite_places', $decoded['users']['legacy-user']['profiles']['only']);
        self::assertArrayHasKey('blocked_algorithms', $decoded['users']['legacy-user']['profiles']['only']);

        @unlink($path);
    }
}
