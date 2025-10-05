<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Support\IndexLogHelper;

use function array_key_exists;
use function implode;
use function sprintf;

/**
 * Validates that mandatory time related metadata has been enriched.
 */
final readonly class MetadataQaInspector
{
    public function __construct(
        private DaypartEnricher $daypartEnricher,
        private SolarEnricher $solarEnricher,
    ) {
    }

    public function inspect(string $filepath, Media $media): void
    {
        $features = $media->getFeatures() ?? [];
        $missing  = [];

        if ($this->daypartEnricher->supports($filepath, $media)
            && !array_key_exists('daypart', $features)
        ) {
            $missing[] = 'daypart';
        }

        if ($this->solarEnricher->supports($filepath, $media)
            && !array_key_exists('isGoldenHour', $features)
        ) {
            $missing[] = 'isGoldenHour';
        }

        if ($missing === []) {
            return;
        }

        $message = sprintf('Warnung: fehlende Zeit-Features (%s).', implode(', ', $missing));
        IndexLogHelper::append($media, $message);
    }
}
