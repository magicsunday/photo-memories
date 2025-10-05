<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Media;

use function is_array;
use function is_bool;
use function is_string;
use function max;
use function min;
use function str_contains;
use function strtolower;

/**
 * Lightweight CLIP-inspired heuristic that maps metadata to scene labels.
 */
final class HeuristicClipSceneTagModel implements VisionSceneTagModelInterface
{
    public function predict(string $filepath, Media $media): array
    {
        $scores = [];

        $this->scoreFaces($scores, $media);
        $this->scorePersons($scores, $media);
        $this->scoreContentKind($scores, $media);
        $this->scoreLocation($scores, $media);
        $this->scoreSeasonalContext($scores, $media);
        $this->scoreBrightness($scores, $media);
        $this->scoreVisualTexture($scores, $media);
        $this->scoreKeywords($scores, $media);
        $this->scoreVideo($scores, $media);

        return $scores;
    }

    /**
     * @param array<string, float> $scores
     */
    private function scoreFaces(array &$scores, Media $media): void
    {
        if ($media->hasFaces() === false) {
            return;
        }

        $faces = $media->getFacesCount();
        $base  = 0.70 + min(0.20, (float) $faces * 0.03);
        $this->bump($scores, 'Porträt', $base);
    }

    /**
     * @param array<string, float> $scores
     */
    private function scorePersons(array &$scores, Media $media): void
    {
        $persons = $media->getPersons();
        if (!is_array($persons)) {
            return;
        }

        $count = 0;
        foreach ($persons as $person) {
            if (is_string($person) && $person !== '') {
                ++$count;
            }
        }

        if ($count <= 0) {
            return;
        }

        $score = 0.58 + min(0.25, (float) $count * 0.05);
        $this->bump($scores, 'Menschen', $score);
    }

    /**
     * @param array<string, float> $scores
     */
    private function scoreContentKind(array &$scores, Media $media): void
    {
        $kind = $media->getContentKind();
        if ($kind === null) {
            return;
        }

        $label = null;
        $score = 0.0;

        if ($kind === ContentKind::SCREENSHOT) {
            $label = 'Screenshot';
            $score = 0.90;
        } elseif ($kind === ContentKind::DOCUMENT) {
            $label = 'Dokument';
            $score = 0.95;
        } elseif ($kind === ContentKind::MAP) {
            $label = 'Karte';
            $score = 0.88;
        } elseif ($kind === ContentKind::SCREEN_RECORDING) {
            $label = 'Bildschirmaufnahme';
            $score = 0.85;
        }

        if ($label === null) {
            return;
        }

        $this->bump($scores, $label, $score);
    }

    /**
     * @param array<string, float> $scores
     */
    private function scoreLocation(array &$scores, Media $media): void
    {
        $lat = $media->getGpsLat();
        $lon = $media->getGpsLon();

        if ($lat !== null && $lon !== null) {
            $this->bump($scores, 'Outdoor', 0.72);
        }
    }

    /**
     * @param array<string, float> $scores
     */
    private function scoreSeasonalContext(array &$scores, Media $media): void
    {
        $features = $media->getFeatures();
        if (!is_array($features)) {
            return;
        }

        $season = $features['season'] ?? null;
        if (is_string($season)) {
            $map = [
                'winter' => ['Winter', 0.62],
                'spring' => ['Frühling', 0.60],
                'summer' => ['Sommer', 0.64],
                'autumn' => ['Herbst', 0.61],
            ];

            $seasonLower = strtolower($season);
            if (isset($map[$seasonLower])) {
                [$label, $score] = $map[$seasonLower];
                $this->bump($scores, $label, $score);
            }
        }

        $isHoliday = $features['isHoliday'] ?? null;
        if (is_bool($isHoliday) && $isHoliday === true) {
            $this->bump($scores, 'Feiertag', 0.55);
        }

        $isWeekend = $features['isWeekend'] ?? null;
        if (is_bool($isWeekend) && $isWeekend === true) {
            $this->bump($scores, 'Wochenende', 0.52);
        }
    }

    /**
     * @param array<string, float> $scores
     */
    private function scoreBrightness(array &$scores, Media $media): void
    {
        $brightness = $media->getBrightness();
        if ($brightness === null) {
            return;
        }

        if ($brightness <= 0.25) {
            $this->bump($scores, 'Nacht', 0.68);

            return;
        }

        if ($brightness >= 0.75) {
            $this->bump($scores, 'Tageslicht', 0.57);

            return;
        }

        $this->bump($scores, 'Dämmerung', 0.54);
    }

    /**
     * @param array<string, float> $scores
     */
    private function scoreVisualTexture(array &$scores, Media $media): void
    {
        $sharpness = $media->getSharpness();
        if ($sharpness !== null && $sharpness >= 0.5) {
            $detailScore = 0.60 + min(0.25, max(0.0, $sharpness - 0.5) * 0.6);
            $this->bump($scores, 'Detailreich', $detailScore);
        }

        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width !== null && $height !== null && $height > 0) {
            $ratio = (float) $width / (float) $height;
            if ($ratio >= 1.9) {
                $this->bump($scores, 'Panorama', 0.64);
            }
        }
    }

    /**
     * @param array<string, float> $scores
     */
    private function scoreKeywords(array &$scores, Media $media): void
    {
        $keywords = $media->getKeywords();
        if (!is_array($keywords)) {
            return;
        }

        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || $keyword === '') {
                continue;
            }

            $k = strtolower($keyword);
            if (str_contains($k, 'beach') || str_contains($k, 'strand')) {
                $this->bump($scores, 'Strand', 0.74);
            } elseif (str_contains($k, 'mountain') || str_contains($k, 'berg')) {
                $this->bump($scores, 'Berge', 0.73);
            } elseif (str_contains($k, 'city') || str_contains($k, 'stadt')) {
                $this->bump($scores, 'Stadt', 0.70);
            } elseif (str_contains($k, 'wedding') || str_contains($k, 'hochzeit')) {
                $this->bump($scores, 'Feier', 0.76);
            }
        }
    }

    /**
     * @param array<string, float> $scores
     */
    private function scoreVideo(array &$scores, Media $media): void
    {
        if ($media->isVideo()) {
            $this->bump($scores, 'Videoaufnahme', 0.58);
        }
    }

    /**
     * @param array<string, float> $scores
     */
    private function bump(array &$scores, string $label, float $score): void
    {
        $clamped  = $this->clamp($score);
        $existing = $scores[$label] ?? 0.0;

        if ($clamped > $existing) {
            $scores[$label] = $clamped;
        }
    }

    private function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}

