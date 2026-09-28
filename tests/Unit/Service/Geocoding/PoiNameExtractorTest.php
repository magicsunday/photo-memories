<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\PoiNameExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoiNameExtractorTest extends TestCase
{
    #[Test]
    public function returnsDefaultNameWhenAvailable(): void
    {
        $extractor = new PoiNameExtractor();

        $name = $extractor->extract([
            'default'    => 'Central Park',
            'localized'  => ['de' => 'Zentralpark'],
            'alternates' => ['Park Central'],
        ]);

        self::assertSame('Central Park', $name);
    }

    #[Test]
    public function fallsBackToLocalizedAndAlternateNames(): void
    {
        $extractor = new PoiNameExtractor();

        $name = $extractor->extract([
            'default'    => null,
            'localized'  => ['de' => '', 'en' => 'Green Meadow'],
            'alternates' => [''],
        ]);

        self::assertSame('Green Meadow', $name);

        $alternateName = $extractor->extract([
            'default'    => null,
            'localized'  => [],
            'alternates' => ['First Alternate', ''],
        ]);

        self::assertSame('First Alternate', $alternateName);
    }
}
