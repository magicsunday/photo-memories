<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\MetadataQaInspectionResult;
use function array_key_exists;

/**
 * Validates that mandatory time related metadata has been enriched.
 */
final readonly class MetadataQaInspector
{
    public function __construct(
        private DaypartEnricher $daypartEnricher,
        private SolarEnricher $solarEnricher,
        private float $minimumTzConfidence = 0.5,
    ) {
    }

    public function inspect(string $filepath, Media $media): MetadataQaInspectionResult
    {
        $features = $media->getFeatures() ?? [];
        $missing  = [];
        $suggestions = [];

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

        $takenAt = $media->getTakenAt();
        if ($takenAt instanceof DateTimeImmutable) {
            if ($media->getTimezoneOffsetMin() === null) {
                $missing[]     = 'timezoneOffsetMin';
                $suggestions[] = 'TimeNormalizer-Konfiguration prüfen';
            }

            $confidence = $media->getTzConfidence();
            if ($confidence === null || $confidence < $this->minimumTzConfidence) {
                $missing[]     = 'tzConfidence';
                $suggestions[] = 'Zeitzonenquellen priorisieren';
            }
        }

        if ($missing === []) {
            return MetadataQaInspectionResult::none();
        }

        return MetadataQaInspectionResult::withIssues($missing, $suggestions);
    }
}
