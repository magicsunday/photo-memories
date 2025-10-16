<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer\Debug;

use MagicSunday\Memories\Service\Clusterer\Debug\VacationDebugContext;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class VacationDebugContextTest extends TestCase
{
    #[Test]
    public function itRecordsSegmentsWhenEnabled(): void
    {
        $context = new VacationDebugContext();
        $context->enable();
        $context->reset();

        $context->recordSegment([
            'start_date' => '2024-05-01',
            'end_date' => '2024-05-03',
            'away_days' => 3,
            'members' => 42,
            'center_count' => 2,
            'radius_km' => 12.5,
            'density' => 0.8,
        ]);

        self::assertTrue($context->isEnabled());
        $segments = $context->getSegments();
        self::assertCount(1, $segments);
        self::assertSame('2024-05-01', $segments[0]['start_date']);

        $context->disable();
        self::assertFalse($context->isEnabled());
        self::assertSame([], $context->getSegments());
    }

    #[Test]
    public function itCollectsWarningsAndAvoidsDuplicates(): void
    {
        $context = new VacationDebugContext();

        $context->recordWarning('Warnung A');
        $context->recordWarning('Warnung A');
        $context->recordWarning('Warnung B');

        self::assertSame(['Warnung A', 'Warnung B'], $context->getWarnings());

        $context->reset();
        self::assertSame([], $context->getWarnings());

        $context->recordWarning('Warnung C');
        $context->disable();
        self::assertSame([], $context->getWarnings());
    }
}
