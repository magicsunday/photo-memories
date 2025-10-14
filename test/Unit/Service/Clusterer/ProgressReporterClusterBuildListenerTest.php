<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use MagicSunday\Memories\Service\Clusterer\Contract\ClusterBuildProgressCallbackInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressHandleInterface;
use MagicSunday\Memories\Service\Clusterer\Contract\ProgressReporterInterface;
use MagicSunday\Memories\Service\Clusterer\DefaultClusterJobRunner;
use MagicSunday\Memories\Service\Clusterer\ProgressReporterClusterBuildListener;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProgressReporterClusterBuildListenerTest extends TestCase
{
    #[Test]
    public function scoringMediaStageReportsProgress(): void
    {
        $progressCalls = [];

        $handle = $this->createMock(ProgressHandleInterface::class);
        $handle->expects(self::exactly(4))
            ->method('setProgress')
            ->willReturnCallback(static function (int $value) use (&$progressCalls): void {
                $progressCalls[] = $value;
            });

        $progressReporter = $this->createMock(ProgressReporterInterface::class);
        $progressReporter->expects(self::once())
            ->method('create')
            ->with('Bewerten', '🏅 Score & Titel', 10)
            ->willReturn($handle);

        self::assertTrue(class_exists(DefaultClusterJobRunner::class));

        $listener = new ProgressReporterClusterBuildListener($progressReporter);

        $listener->onStageStart(ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA, 10);
        $listener->onStageProgress(ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA, 5, 10);
        $listener->onStageFinish(ClusterBuildProgressCallbackInterface::STAGE_SCORING_MEDIA, 10);

        self::assertSame([0, 0, 5, 10], $progressCalls);
    }
}
