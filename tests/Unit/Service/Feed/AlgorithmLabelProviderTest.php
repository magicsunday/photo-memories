<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Feed;

use MagicSunday\Memories\Service\Feed\AlgorithmLabelProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \MagicSunday\Memories\Service\Feed\AlgorithmLabelProvider
 */
final class AlgorithmLabelProviderTest extends TestCase
{
    public function testReturnsConfiguredLabelWhenAvailable(): void
    {
        $provider = new AlgorithmLabelProvider([
            'holiday_event' => 'Feiertage',
        ]);

        self::assertSame('Feiertage', $provider->getLabel('holiday_event'));
    }

    public function testBuildsFallbackLabelForUnknownAlgorithm(): void
    {
        $provider = new AlgorithmLabelProvider();

        self::assertSame('Winter Magic', $provider->getLabel('winter_magic'));
    }

    public function testReturnsGenericLabelForEmptyAlgorithm(): void
    {
        $provider = new AlgorithmLabelProvider();

        self::assertSame('Strategie', $provider->getLabel(''));
    }
}
