<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use DateTimeImmutable;
use MagicSunday\Memories\Service\Metadata\CalendarFeatureEnricher;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class CalendarFeatureEnricherTest extends TestCase
{
    #[Test]
    #[DataProvider('germanHolidayProvider')]
    public function marksGermanHolidays(DateTimeImmutable $date, string $expectedId): void
    {
        $media = $this->makeMedia(
            id: 1,
            path: '/fixtures/holiday.jpg',
            takenAt: $date,
        );

        $enricher = new CalendarFeatureEnricher();
        $result   = $enricher->extract($media->getPath(), $media);

        $features = $result->getFeatures();

        self::assertIsArray($features);
        self::assertArrayHasKey('calendar', $features);
        self::assertTrue($features['calendar']['isHoliday']);
        self::assertSame($expectedId, $features['calendar']['holidayId']);
    }

    /**
     * @return iterable<string, array{DateTimeImmutable, string}>
     */
    public static function germanHolidayProvider(): iterable
    {
        yield 'good friday' => [
            new DateTimeImmutable('2024-03-29T10:00:00+00:00'),
            'de-goodfriday-2024',
        ];

        yield 'easter monday' => [
            new DateTimeImmutable('2024-04-01T09:30:00+00:00'),
            'de-eastermon-2024',
        ];

        yield 'ascension day' => [
            new DateTimeImmutable('2024-05-09T12:00:00+00:00'),
            'de-ascension-2024',
        ];

        yield 'whit monday' => [
            new DateTimeImmutable('2024-05-20T08:15:00+00:00'),
            'de-whitmonday-2024',
        ];

        yield 'german unity day' => [
            new DateTimeImmutable('2024-10-03T14:45:00+00:00'),
            'de-unity-2024',
        ];

        yield 'easter monday timezone shift' => [
            new DateTimeImmutable('2024-04-01T00:30:00+02:00'),
            'de-eastermon-2024',
        ];
    }

    #[Test]
    public function leavesNonHolidayUnchanged(): void
    {
        $media = $this->makeMedia(
            id: 2,
            path: '/fixtures/plain-day.jpg',
            takenAt: new DateTimeImmutable('2024-02-15T11:00:00+00:00'),
        );

        $enricher = new CalendarFeatureEnricher();
        $result   = $enricher->extract($media->getPath(), $media);

        $features = $result->getFeatures();

        self::assertIsArray($features);
        self::assertArrayHasKey('calendar', $features);
        self::assertFalse($features['calendar']['isHoliday']);
        self::assertArrayNotHasKey('holidayId', $features['calendar']);
    }
}
