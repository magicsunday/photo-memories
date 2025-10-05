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

use function array_filter;
use function array_unique;
use function array_values;
use function basename;
use function is_array;
use function is_string;
use function max;
use function min;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;

/**
 * Classifies media into broad content categories to support downstream filtering.
 */
final class ContentClassifierExtractor implements SingleMetadataExtractorInterface
{
    /** Keywords associated with screenshot detection. */
    private const array SCREENSHOT_KEYWORDS = [
        'screenshot',
        'screen-shot',
        'screen_capture',
        'screen-capture',
        'screencap',
        'bildschirmfoto',
        'bildschirmaufnahme',
    ];

    /** Keywords indicating document-style media. */
    private const array DOCUMENT_KEYWORDS = [
        'document',
        'scan',
        'scanned',
        'rechnung',
        'beleg',
        'invoice',
        'note',
        'notiz',
        'vertrag',
        'certificate',
    ];

    /** Keywords used to recognise map-related media. */
    private const array MAP_KEYWORDS = [
        'map',
        'maps',
        'karte',
        'stadtplan',
        'navigation',
        'route',
        'navi',
        'osm',
    ];

    /** Keywords marking screen recordings. */
    private const array SCREEN_RECORD_KEYWORDS = [
        'screenrecord',
        'screen-record',
        'screen_record',
        'screenrecording',
        'screen-recording',
        'bildschirmaufnahme',
        'screenrec',
    ];

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();
        if ($mime === null) {
            return true;
        }

        return str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        $kind = $this->classify($media, $filepath);
        if ($kind === null) {
            return $media;
        }

        $media->setContentKind($kind);
        if ($this->shouldHide($kind)) {
            $media->setNoShow(true);
        }

        return $media;
    }

    private function classify(Media $media, string $filepath): ?ContentKind
    {
        $tokens = $this->tokensFromMedia($media, $filepath);

        if ($media->isVideo() && $this->isScreenRecording($media, $tokens)) {
            return ContentKind::SCREEN_RECORDING;
        }

        if ($this->isScreenshot($media, $tokens)) {
            return ContentKind::SCREENSHOT;
        }

        if ($this->isMap($media, $tokens)) {
            return ContentKind::MAP;
        }

        if ($this->isDocument($media, $tokens)) {
            return ContentKind::DOCUMENT;
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     */
    private function isScreenshot(Media $media, array $tokens): bool
    {
        if ($this->matchesAnyKeyword($tokens, self::SCREENSHOT_KEYWORDS)) {
            return true;
        }

        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width === null || $height === null) {
            return false;
        }

        $cameraMake  = $media->getCameraMake();
        $cameraModel = $media->getCameraModel();
        $iso         = $media->getIso();

        if ($cameraMake !== null || $cameraModel !== null || ($iso !== null && $iso > 0)) {
            return false;
        }

        $longSide  = max($width, $height);
        $shortSide = min($width, $height);
        if ($shortSide === 0) {
            return false;
        }

        $ratio = $longSide / (float) $shortSide;
        if ($longSide < 720 || $ratio < 1.55 || $ratio > 2.40) {
            return false;
        }

        $sharpness = $media->getSharpness();
        if ($sharpness !== null && $sharpness > 0.35) {
            return false;
        }

        $colorfulness = $media->getColorfulness();
        return !($colorfulness !== null && $colorfulness > 0.45);
    }

    /**
     * @param list<string> $tokens
     */
    private function isDocument(Media $media, array $tokens): bool
    {
        if ($this->matchesAnyKeyword($tokens, self::DOCUMENT_KEYWORDS)) {
            return true;
        }

        $colorfulness = $media->getColorfulness();
        $contrast     = $media->getContrast();
        $brightness   = $media->getBrightness();

        if ($colorfulness !== null && $colorfulness <= 0.2) {
            if ($contrast !== null && $contrast >= 0.55) {
                return true;
            }

            if ($brightness !== null && ($brightness <= 0.18 || $brightness >= 0.82)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tokens
     */
    private function isMap(Media $media, array $tokens): bool
    {
        if ($this->matchesAnyKeyword($tokens, self::MAP_KEYWORDS)) {
            return true;
        }

        $colorfulness = $media->getColorfulness();
        $entropy      = $media->getEntropy();

        return $colorfulness !== null && $colorfulness >= 0.45 && $entropy !== null && $entropy <= 0.55;
    }

    /**
     * @param list<string> $tokens
     */
    private function isScreenRecording(Media $media, array $tokens): bool
    {
        if ($this->matchesAnyKeyword($tokens, self::SCREEN_RECORD_KEYWORDS)) {
            return true;
        }

        $fps   = $media->getVideoFps();
        $codec = $media->getVideoCodec();

        if ($fps !== null && $fps >= 45.0) {
            $cameraMake  = $media->getCameraMake();
            $cameraModel = $media->getCameraModel();

            if ($cameraMake === null && $cameraModel === null) {
                return true;
            }
        }

        if ($codec !== null) {
            $codecLower = strtolower($codec);
            if (str_contains($codecLower, 'screen') || str_contains($codecLower, 'display')) {
                return true;
            }
        }

        return false;
    }

    private function shouldHide(ContentKind $kind): bool
    {
        return match ($kind) {
            ContentKind::SCREENSHOT,
            ContentKind::DOCUMENT,
            ContentKind::MAP,
            ContentKind::SCREEN_RECORDING => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    private function tokensFromMedia(Media $media, string $filepath): array
    {
        $tokens = [];
        $features = $media->getFeatures();
        if (is_array($features)) {
            $pathTokens = $features['pathTokens'] ?? null;
            if (is_array($pathTokens)) {
                foreach ($pathTokens as $token) {
                    if (!is_string($token) || $token === '') {
                        continue;
                    }

                    $tokens[] = strtolower($token);
                }
            }

            $hint = $features['filenameHint'] ?? null;
            if (is_string($hint) && $hint !== '') {
                $tokens[] = strtolower($hint);
            }
        }

        $basename = strtolower(basename($filepath));
        $tokens[] = str_replace([' ', '_'], '-', $basename);

        $extra = preg_split('/[^a-z0-9]+/i', $basename) ?: [];
        foreach ($extra as $token) {
            if ($token !== '') {
                $tokens[] = strtolower($token);
            }
        }

        /** @var list<string> $filtered */
        $filtered = array_values(array_unique(array_filter($tokens, static fn (string $token): bool => $token !== '')));

        return $filtered;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $keywords
     */
    private function matchesAnyKeyword(array $tokens, array $keywords): bool
    {
        if ($tokens === []) {
            return false;
        }

        foreach ($keywords as $keyword) {
            foreach ($tokens as $token) {
                if ($token === $keyword || str_contains($token, $keyword)) {
                    return true;
                }

                if (str_ends_with($token, '-' . $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }
}
