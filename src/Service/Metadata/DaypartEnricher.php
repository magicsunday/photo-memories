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
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;

/**
 * Class DaypartEnricher
 */
final readonly class DaypartEnricher implements SingleMetadataExtractorInterface
{
    public function __construct(private CaptureTimeResolver $captureTimeResolver)
    {
    }

    public function supports(string $filepath, Media $media): bool
    {
        return $media->getTakenAt() instanceof DateTimeImmutable
            || $media->getCapturedLocal() instanceof DateTimeImmutable;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $local = $this->captureTimeResolver->resolve($media);
        if (!$local instanceof DateTimeImmutable) {
            return $media;
        }

        $h = (int) $local->format('G');

        $part = match (true) {
            $h >= 6 && $h <= 10  => 'morning',
            $h >= 11 && $h <= 16 => 'noon',
            $h >= 17 && $h <= 21 => 'evening',
            default              => 'night',
        };

        $features            = $media->getFeatures() ?? [];
        $features['daypart'] = $part;
        $media->setFeatures($features);

        return $media;
    }
}
