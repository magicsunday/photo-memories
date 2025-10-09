<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata\Feature;

use InvalidArgumentException;
use MagicSunday\Memories\Service\Metadata\Feature\MediaFeatureBag;
use PHPUnit\Framework\TestCase;

final class MediaFeatureBagTest extends TestCase
{
    public function testCreatesBagFromNamespacedArray(): void
    {
        $bag = MediaFeatureBag::fromArray([
            'calendar' => [
                'daypart'   => 'evening',
                'isHoliday' => true,
                'meta'      => [
                    'score' => 0.75,
                    'tags'  => ['sunset', 'city'],
                ],
            ],
            'file' => [
                'pathTokens' => ['foo', 'bar'],
            ],
        ]);

        self::assertSame('evening', $bag->calendarDaypart());
        self::assertTrue($bag->calendarIsHoliday());
        self::assertSame(['foo', 'bar'], $bag->filePathTokens());

        $calendar = $bag->namespaceValues('calendar');
        self::assertArrayHasKey('meta', $calendar);
        self::assertSame(['sunset', 'city'], $calendar['meta']['tags']);
    }

    public function testRejectsNonNamespacedPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MediaFeatureBag::fromArray([
            'calendar' => 'evening',
        ]);
    }

    public function testRejectsUnsupportedValueTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MediaFeatureBag::fromArray([
            'calendar' => [
                'invalid' => new \stdClass(),
            ],
        ]);
    }

    public function testSetFilePathTokensValidatesStringLists(): void
    {
        $bag = MediaFeatureBag::create();
        $bag->setFilePathTokens(['foo', 'bar']);

        self::assertSame(['foo', 'bar'], $bag->filePathTokens());
    }

    public function testSetFilePathTokensRejectsNonStrings(): void
    {
        $bag = MediaFeatureBag::create();

        $this->expectException(InvalidArgumentException::class);
        $bag->setFilePathTokens(['foo', 42]);
    }

    public function testSetCalendarDaypartRejectsInvalidValue(): void
    {
        $bag = MediaFeatureBag::create();

        $this->expectException(InvalidArgumentException::class);
        $bag->setCalendarDaypart('sunrise');
    }

    public function testSetCalendarDayOfWeekRejectsOutOfRange(): void
    {
        $bag = MediaFeatureBag::create();

        $this->expectException(InvalidArgumentException::class);
        $bag->setCalendarDayOfWeek(8);
    }

    public function testSetCalendarSeasonRejectsInvalidValue(): void
    {
        $bag = MediaFeatureBag::create();

        $this->expectException(InvalidArgumentException::class);
        $bag->setCalendarSeason('monsoon');
    }

    public function testSetFileNameHintRejectsInvalidValue(): void
    {
        $bag = MediaFeatureBag::create();

        $this->expectException(InvalidArgumentException::class);
        $bag->setFileNameHint('unknown');
    }

    public function testSetCalendarHolidayIdRejectsInvalidFormat(): void
    {
        $bag = MediaFeatureBag::create();

        $this->expectException(InvalidArgumentException::class);
        $bag->setCalendarHolidayId('Weihnachten');
    }
}
