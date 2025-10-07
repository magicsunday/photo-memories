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
use function sys_get_temp_dir;
use function uniqid;

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

        $storage->markFavourite('alice', 'default', 'cluster-1', true);
        $storage->markFavourite('alice', 'default', 'cluster-2', true);
        $storage->setAlgorithmOptOut('alice', 'default', 'holiday_event', true);

        $updated = $storage->getPreferences('alice', 'default');
        self::assertSame(['cluster-1', 'cluster-2'], $updated->getFavourites());
        self::assertSame(['holiday_event'], $updated->getHiddenAlgorithms());

        $storage->markFavourite('alice', 'default', 'cluster-1', false);
        $storage->setAlgorithmOptOut('alice', 'default', 'holiday_event', false);

        $final = $storage->getPreferences('alice', 'default');
        self::assertSame(['cluster-2'], $final->getFavourites());
        self::assertSame([], $final->getHiddenAlgorithms());

        self::assertTrue(file_exists($path));
        @unlink($path);
    }
}
