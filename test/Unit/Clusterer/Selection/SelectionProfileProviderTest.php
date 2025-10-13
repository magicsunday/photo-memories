<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer\Selection;

use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Clusterer\Selection\VacationSelectionOptions;
use MagicSunday\Memories\Service\Metadata\Support\FaceDetectionAvailability;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider
 */
final class SelectionProfileProviderTest extends TestCase
{
    public function testItDowngradesTransitProfileWhenFaceDetectionUnavailable(): void
    {
        $availability = new FaceDetectionAvailability();
        $availability->markUnavailable();

        $defaultOptions = new VacationSelectionOptions();
        $profiles       = [
            'vacation_weekend_transit' => [
                'face_bonus' => 0.32,
                'video_bonus' => 0.27,
            ],
        ];

        $provider = new SelectionProfileProvider(
            $defaultOptions,
            'default',
            $profiles,
            [],
            $availability,
        );

        $options = $provider->createOptions('vacation_weekend_transit');

        self::assertSame(0.12, $options->faceBonus);
        self::assertSame(0.22, $options->videoBonus);
        self::assertFalse($options->faceDetectionAvailable);
    }
}
