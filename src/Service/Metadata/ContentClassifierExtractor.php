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

use function array_any;
use function array_filter;
use function array_unique;
use function array_values;
use function basename;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function usort;

/**
 * Classifies media into broad content categories to support downstream filtering.
 */
final class ContentClassifierExtractor implements SingleMetadataExtractorInterface
{
    private const array SCREENSHOT_KEYWORDS = [
        'screenshot',
        'screen-shot',
        'screen_capture',
        'screen-capture',
        'screencap',
        'bildschirmfoto',
        'bildschirmaufnahme',
    ];

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

    private const array SCREEN_RECORD_KEYWORDS = [
        'screenrecord',
        'screen-record',
        'screen_record',
        'screenrecording',
        'screen-recording',
        'bildschirmaufnahme',
        'screenrec',
    ];

    private const array VISION_KEYWORDS = [
        'screenshot'       => ['screenshot', 'screen'],
        'document'        => ['document', 'paper', 'receipt'],
        'map'             => ['map', 'navigation', 'atlas'],
        'screenRecording' => ['screen recording', 'screenrec', 'display capture'],
    ];

    public function __construct(
        private float $screenshotAspectRatioMin = 1.55,
        private float $screenshotAspectRatioMax = 2.40,
        private int $screenshotMinLongSide = 720,
        private float $screenshotSharpnessMax = 0.35,
        private float $screenshotColorfulnessMax = 0.45,
        private float $documentColorfulnessMax = 0.20,
        private float $documentContrastMin = 0.55,
        private float $documentBrightnessLow = 0.18,
        private float $documentBrightnessHigh = 0.82,
        private float $mapColorfulnessMin = 0.45,
        private float $mapEntropyMax = 0.55,
        private float $screenRecordingFpsThreshold = 45.0,
        private float $minConfidenceToHide = 0.65,
        private float $visionTagConfidenceThreshold = 0.55,
        private float $visionBoostWeight = 0.25,
    ) {
    }

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
        $bag = $media->getFeatureBag();
        $bag->setClassificationKind(null);
        $bag->setClassificationConfidence(null);
        $bag->setClassificationShouldHide(null);

        $result = $this->classify($media, $filepath);
        if ($result === null) {
            $media->setFeatureBag($bag);

            return $media;
        }

        $media->setContentKind($result['kind']);
        $bag->setClassificationKind($result['kind']);
        $bag->setClassificationConfidence($result['confidence']);

        $shouldHide = $result['confidence'] >= $this->minConfidenceToHide && $this->shouldHide($result['kind']);
        $bag->setClassificationShouldHide($shouldHide);

        if ($shouldHide) {
            $media->setNoShow(true);
        }

        $media->setFeatureBag($bag);

        return $media;
    }

    /**
     * @return array{kind: ContentKind, confidence: float}|null
     */
    private function classify(Media $media, string $filepath): ?array
    {
        $tokens = $this->tokensFromMedia($media, $filepath);

        $scores = [];

        if ($media->isVideo()) {
            $score = $this->scoreScreenRecording($media, $tokens);
            if ($score !== null) {
                $scores[] = ['kind' => ContentKind::SCREEN_RECORDING, 'confidence' => $score];
            }
        }

        $screenshotScore = $this->scoreScreenshot($media, $tokens);
        if ($screenshotScore !== null) {
            $scores[] = ['kind' => ContentKind::SCREENSHOT, 'confidence' => $screenshotScore];
        }

        $mapScore = $this->scoreMap($media, $tokens);
        if ($mapScore !== null) {
            $scores[] = ['kind' => ContentKind::MAP, 'confidence' => $mapScore];
        }

        $documentScore = $this->scoreDocument($media, $tokens);
        if ($documentScore !== null) {
            $scores[] = ['kind' => ContentKind::DOCUMENT, 'confidence' => $documentScore];
        }

        if ($scores === []) {
            return null;
        }

        usort(
            $scores,
            static fn (array $lhs, array $rhs): int => $rhs['confidence'] <=> $lhs['confidence']
        );

        return $scores[0];
    }

    /**
     * @param list<string> $tokens
     */
    private function scoreScreenshot(Media $media, array $tokens): ?float
    {
        $score = 0.0;
        if ($this->matchesAnyKeyword($tokens, self::SCREENSHOT_KEYWORDS)) {
            $score += 0.5;
        }

        $width  = $media->getWidth();
        $height = $media->getHeight();
        if ($width === null || $height === null) {
            return $this->confidenceOrNull($score);
        }

        $cameraMake  = $media->getCameraMake();
        $cameraModel = $media->getCameraModel();
        $iso         = $media->getIso();

        if ($cameraMake !== null || $cameraModel !== null || ($iso !== null && $iso > 0)) {
            return $this->confidenceOrNull($score);
        }

        $longSide  = max($width, $height);
        $shortSide = min($width, $height);
        if ($shortSide === 0) {
            return $this->confidenceOrNull($score);
        }

        $ratio = $longSide / (float) $shortSide;
        if ($longSide >= $this->screenshotMinLongSide && $ratio >= $this->screenshotAspectRatioMin && $ratio <= $this->screenshotAspectRatioMax) {
            $score += 0.35;
        }

        $sharpness = $media->getSharpness();
        if ($sharpness !== null && $sharpness <= $this->screenshotSharpnessMax) {
            $score += 0.1;
        }

        $colorfulness = $media->getColorfulness();
        if ($colorfulness !== null && $colorfulness <= $this->screenshotColorfulnessMax) {
            $score += 0.1;
        }

        $score += $this->visionBoost($media, self::VISION_KEYWORDS['screenshot']);

        return $this->confidenceOrNull($score);
    }

    /**
     * @param list<string> $tokens
     */
    private function scoreDocument(Media $media, array $tokens): ?float
    {
        $score = 0.0;
        if ($this->matchesAnyKeyword($tokens, self::DOCUMENT_KEYWORDS)) {
            $score += 0.45;
        }

        $colorfulness = $media->getColorfulness();
        $contrast     = $media->getContrast();
        $brightness   = $media->getBrightness();

        if ($colorfulness !== null && $colorfulness <= $this->documentColorfulnessMax) {
            $score += 0.30;
        }

        if ($contrast !== null && $contrast >= $this->documentContrastMin) {
            $score += 0.15;
        }

        if ($brightness !== null && ($brightness <= $this->documentBrightnessLow || $brightness >= $this->documentBrightnessHigh)) {
            $score += 0.10;
        }

        $score += $this->visionBoost($media, self::VISION_KEYWORDS['document']);

        return $this->confidenceOrNull($score);
    }

    /**
     * @param list<string> $tokens
     */
    private function scoreMap(Media $media, array $tokens): ?float
    {
        $score = 0.0;
        if ($this->matchesAnyKeyword($tokens, self::MAP_KEYWORDS)) {
            $score += 0.5;
        }

        $colorfulness = $media->getColorfulness();
        $entropy      = $media->getEntropy();

        if ($colorfulness !== null && $colorfulness >= $this->mapColorfulnessMin && $entropy !== null && $entropy <= $this->mapEntropyMax) {
            $score += 0.30;
        }

        $score += $this->visionBoost($media, self::VISION_KEYWORDS['map']);

        return $this->confidenceOrNull($score);
    }

    /**
     * @param list<string> $tokens
     */
    private function scoreScreenRecording(Media $media, array $tokens): ?float
    {
        $score = 0.0;
        if ($this->matchesAnyKeyword($tokens, self::SCREEN_RECORD_KEYWORDS)) {
            $score += 0.5;
        }

        $fps = $media->getVideoFps();
        if ($fps !== null && $fps >= $this->screenRecordingFpsThreshold) {
            $cameraMake  = $media->getCameraMake();
            $cameraModel = $media->getCameraModel();
            if ($cameraMake === null && $cameraModel === null) {
                $score += 0.20;
            }
        }

        $codec = $media->getVideoCodec();
        if ($codec !== null) {
            $codecLower = strtolower($codec);
            if (str_contains($codecLower, 'screen') || str_contains($codecLower, 'display')) {
                $score += 0.20;
            }
        }

        $score += $this->visionBoost($media, self::VISION_KEYWORDS['screenRecording']);

        return $this->confidenceOrNull($score);
    }

    private function shouldHide(ContentKind $kind): bool
    {
        return match ($kind) {
            ContentKind::SCREENSHOT,
            ContentKind::DOCUMENT,
            ContentKind::MAP,
            ContentKind::SCREEN_RECORDING => true,
            default                       => false,
        };
    }

    /**
     * @return list<string>
     */
    private function tokensFromMedia(Media $media, string $filepath): array
    {
        $tokens   = [];
        $bag      = $media->getFeatureBag();
        $pathTokens = $bag->filePathTokens();
        if ($pathTokens !== null) {
            foreach ($pathTokens as $token) {
                if ($token === '') {
                    continue;
                }

                $tokens[] = strtolower($token);
            }
        }

        $hint = $bag->fileNameHint();
        if ($hint !== null && $hint !== '') {
            $tokens[] = strtolower($hint);
        }

        $basename = strtolower(basename($filepath));
        $tokens[] = str_replace([' ', '_'], '-', $basename);

        $extra = preg_split('/[^a-z0-9]+/i', $basename);
        if ($extra === false) {
            $extra = [];
        }
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

        return array_any(
            $keywords,
            static fn (string $keyword): bool => array_any(
                $tokens,
                static fn (string $token): bool => $token === $keyword
                    || str_contains($token, $keyword)
                    || str_ends_with($token, '-' . $keyword)
            )
        );
    }

    /**
     * @param list<string> $keywords
     */
    private function visionBoost(Media $media, array $keywords): float
    {
        $tags = $media->getSceneTags();
        if (!is_array($tags) || $tags === []) {
            return 0.0;
        }

        $maxScore = 0.0;
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $label = $tag['label'] ?? null;
            $score = $tag['score'] ?? null;
            if (!is_string($label) || !is_float($score) && !is_int($score)) {
                continue;
            }

            $labelLower = strtolower($label);
            foreach ($keywords as $keyword) {
                if (str_contains($labelLower, $keyword)) {
                    $maxScore = max($maxScore, (float) $score);
                }
            }
        }

        if ($maxScore < $this->visionTagConfidenceThreshold) {
            return 0.0;
        }

        return min(1.0, $maxScore) * $this->visionBoostWeight;
    }

    private function confidenceOrNull(float $score): ?float
    {
        if ($score <= 0.0) {
            return null;
        }

        return $score >= 1.0 ? 1.0 : $score;
    }
}
