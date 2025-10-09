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
use MagicSunday\Memories\Entity\Enum\ContentKind;
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

    public function testClassificationNamespaceRoundtrip(): void
    {
        $bag = MediaFeatureBag::create();
        $bag->setClassificationKind(ContentKind::DOCUMENT);
        $bag->setClassificationConfidence(0.75);
        $bag->setClassificationShouldHide(true);

        self::assertSame(ContentKind::DOCUMENT, $bag->classificationKind());
        self::assertSame(0.75, $bag->classificationConfidence());
        self::assertTrue($bag->classificationShouldHide());

        $values = $bag->namespaceValues(MediaFeatureBag::NAMESPACE_CLASSIFICATION);
        self::assertSame(
            [
                'kind'        => ContentKind::DOCUMENT->value,
                'confidence'  => 0.75,
                'shouldHide'  => true,
            ],
            $values
        );
    }

    public function testClassificationConfidenceRejectsOutOfRange(): void
    {
        $bag = MediaFeatureBag::create();

        $this->expectException(InvalidArgumentException::class);
        $bag->setClassificationConfidence(1.5);
    }

    public function testClassificationKindRejectsUnknownValue(): void
    {
        $bag = MediaFeatureBag::fromArray([
            MediaFeatureBag::NAMESPACE_CLASSIFICATION => [
                'kind' => 'invalid',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $bag->classificationKind();
    }

    public function testClassificationConfidenceRejectsNonNumeric(): void
    {
        $bag = MediaFeatureBag::fromArray([
            MediaFeatureBag::NAMESPACE_CLASSIFICATION => [
                'confidence' => 'high',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $bag->classificationConfidence();
    }

    public function testClassificationShouldHideRejectsNonBoolean(): void
    {
        $bag = MediaFeatureBag::fromArray([
            MediaFeatureBag::NAMESPACE_CLASSIFICATION => [
                'shouldHide' => 'yes',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $bag->classificationShouldHide();
    }

    public function testMigratesDotSeparatedLegacyPayload(): void
    {
        $bag = MediaFeatureBag::fromArray([
            'calendar.daypart'   => 'night',
            'file.filenameHint'  => 'pano',
            'solar.isGoldenHour' => true,
        ]);

        self::assertSame('night', $bag->calendarDaypart());
        self::assertSame('pano', $bag->fileNameHint());
        self::assertTrue($bag->solarIsGoldenHour());
    }

    public function testMigratesLegacyTopLevelKeys(): void
    {
        $bag = MediaFeatureBag::fromArray([
            'daypart'    => 'evening',
            'dow'        => 6,
            'pathTokens' => ['foo', 'bar'],
        ]);

        self::assertSame('evening', $bag->calendarDaypart());
        self::assertSame(6, $bag->calendarDayOfWeek());
        self::assertSame(['foo', 'bar'], $bag->filePathTokens());
    }
}
