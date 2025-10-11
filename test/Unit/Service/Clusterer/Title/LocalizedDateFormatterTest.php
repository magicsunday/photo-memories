<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Title;

use MagicSunday\Memories\Service\Clusterer\Title\LocalizedDateFormatter;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(LocalizedDateFormatter::class)]
final class LocalizedDateFormatterTest extends TestCase
{
    #[Test]
    public function formatsGermanDatesWithShortMonths(): void
    {
        $formatter = new LocalizedDateFormatter();

        self::assertSame('1. Jul. 2024', $formatter->formatDate((new \DateTimeImmutable('2024-07-01'))->getTimestamp()));

        $sameDay = [
            'from' => (new \DateTimeImmutable('2024-07-01 08:00:00'))->getTimestamp(),
            'to'   => (new \DateTimeImmutable('2024-07-01 22:00:00'))->getTimestamp(),
        ];
        self::assertSame('1. Jul. 2024', $formatter->formatRange($sameDay));

        $sameMonth = [
            'from' => (new \DateTimeImmutable('2024-07-01'))->getTimestamp(),
            'to'   => (new \DateTimeImmutable('2024-07-03'))->getTimestamp(),
        ];
        self::assertSame('1.–3. Jul. 2024', $formatter->formatRange($sameMonth));

        $differentMonth = [
            'from' => (new \DateTimeImmutable('2024-07-28'))->getTimestamp(),
            'to'   => (new \DateTimeImmutable('2024-08-02'))->getTimestamp(),
        ];
        self::assertSame('28. Jul. – 2. Aug. 2024', $formatter->formatRange($differentMonth));

        $differentYear = [
            'from' => (new \DateTimeImmutable('2024-12-28'))->getTimestamp(),
            'to'   => (new \DateTimeImmutable('2025-01-02'))->getTimestamp(),
        ];
        self::assertSame('28. Dez. 2024 – 2. Jan. 2025', $formatter->formatRange($differentYear));
    }

    #[Test]
    public function respectsRequestedLocale(): void
    {
        $formatter = new LocalizedDateFormatter();

        $range = [
            'from' => (new \DateTimeImmutable('2024-07-01'))->getTimestamp(),
            'to'   => (new \DateTimeImmutable('2024-07-03'))->getTimestamp(),
        ];

        self::assertSame('1.–3. Jul 2024', $formatter->formatRange($range, 'en'));
        self::assertSame('1. Jul 2024', $formatter->formatDate((new \DateTimeImmutable('2024-07-01'))->getTimestamp(), 'en'));
    }
}
