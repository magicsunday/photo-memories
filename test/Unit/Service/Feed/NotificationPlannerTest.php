<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Service\Feed\NotificationPlanner;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

#[CoversClass(NotificationPlanner::class)]
final class NotificationPlannerTest extends TestCase
{
    #[Test]
    public function buildsScheduleForConfiguredChannels(): void
    {
        $planner = new NotificationPlanner(
            [
                'push'  => ['lead_times' => ['P0D', 'P1D'], 'send_time' => '09:30'],
                'email' => ['lead_times' => ['P2D'], 'send_time' => '08:00'],
            ],
            '09:00',
            'Europe/Berlin'
        );

        $item = new MemoryFeedItem(
            algorithm: 'time_similarity',
            title: 'Weekend Trip',
            subtitle: 'Mai 2024',
            coverMediaId: null,
            memberIds: [1, 2, 3],
            score: 0.82,
            params: [
                'time_range' => [
                    'from' => (new DateTimeImmutable('2024-05-05 18:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp(),
                    'to'   => (new DateTimeImmutable('2024-05-07 12:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp(),
                ],
            ],
        );

        $reference = new DateTimeImmutable('2024-05-01 07:00:00', new DateTimeZone('Europe/Berlin'));

        $plan = $planner->planForItem($item, $reference);

        self::assertCount(3, $plan);

        $channels = array_map(static fn (array $entry): string => $entry['kanal'], $plan);
        self::assertSame(['email', 'push', 'push'], $channels);

        self::assertSame('P2D', $plan[0]['vorlauf']);
        self::assertSame('P1D', $plan[1]['vorlauf']);
        self::assertSame('P0D', $plan[2]['vorlauf']);
    }

    #[Test]
    public function returnsEmptyScheduleWhenNoTimeRangeIsPresent(): void
    {
        $planner = new NotificationPlanner();

        $item = new MemoryFeedItem(
            algorithm: 'time_similarity',
            title: 'Untimed Memory',
            subtitle: 'Ohne Zeitraum',
            coverMediaId: null,
            memberIds: [5],
            score: 0.5,
            params: [],
        );

        $plan = $planner->planForItem($item, new DateTimeImmutable('2024-01-01 00:00:00', new DateTimeZone('UTC')));

        self::assertSame([], $plan);
    }
}
