<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use InvalidArgumentException;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Support\IndexLogHelper;

use function array_keys;
use function array_map;
use function array_slice;
use function arsort;
use function implode;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Persists top-N scene labels with confidence scores on the media entity.
 */
final readonly class ClipSceneTagExtractor implements SingleMetadataExtractorInterface
{
    /**
     * @param int   $maxTags  maximum number of scene tags to persist
     * @param float $minScore minimum confidence required to accept a tag
     */
    public function __construct(
        private VisionSceneTagModelInterface $model,
        private int $maxTags = 6,
        private float $minScore = 0.3,
    ) {
        if ($this->maxTags < 1) {
            throw new InvalidArgumentException('maxTags must be >= 1');
        }

        if ($this->minScore < 0.0) {
            throw new InvalidArgumentException('minScore must be >= 0');
        }
    }

    public function supports(string $filepath, Media $media): bool
    {
        if ($media->isNoShow() || $media->isLowQuality()) {
            return false;
        }

        $mime = $media->getMime();
        if ($mime === null) {
            return true;
        }

        return str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        $predictions = $this->model->predict($filepath, $media);

        $tags = $this->selectTopTags($predictions);
        if ($tags === []) {
            $media->setSceneTags(null);

            return $media;
        }

        $media->setSceneTags($tags);
        IndexLogHelper::append($media, $this->formatSceneSummary($tags));

        return $media;
    }

    /**
     * @param array<string, float> $predictions
     *
     * @return list<array{label: string, score: float}>
     */
    private function selectTopTags(array $predictions): array
    {
        if ($predictions === []) {
            return [];
        }

        /** @var array<string, float> $filtered */
        $filtered = [];

        foreach ($predictions as $label => $score) {
            if (!is_string($label) || $label === '') {
                continue;
            }

            if (!is_float($score) && !is_int($score)) {
                continue;
            }

            $value = (float) $score;
            if ($value < $this->minScore) {
                continue;
            }

            if ($value > 1.0) {
                $value = 1.0;
            }

            $filtered[$label] = $value;
        }

        if ($filtered === []) {
            return [];
        }

        arsort($filtered);

        $sliced = array_slice($filtered, 0, $this->maxTags, true);

        return array_map(
            static fn (string $label, float $score): array => ['label' => $label, 'score' => $score],
            array_keys($sliced),
            $sliced
        );
    }

    /**
     * @param list<array{label: string, score: float}> $tags
     */
    private function formatSceneSummary(array $tags): string
    {
        $parts = array_map(
            static fn (array $tag): string => sprintf('%s(%.2f)', $tag['label'], $tag['score']),
            $tags
        );

        return sprintf('scene=%s', implode(',', $parts));
    }
}
