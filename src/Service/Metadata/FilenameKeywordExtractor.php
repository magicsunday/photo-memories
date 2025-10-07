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
use MagicSunday\Memories\Service\Metadata\Support\PathTokensTrait;

use function str_contains;
use function str_starts_with;

/**
 * Adds path tokens and filename-based hint.
 */
final class FilenameKeywordExtractor implements SingleMetadataExtractorInterface
{
    use PathTokensTrait;

    public function supports(string $filepath, Media $media): bool
    {
        return true;
    }

    public function extract(string $filepath, Media $media): Media
    {
        $tokens = $this->tokenizePath($filepath);

        $bag  = $media->getFeatureBag();
        $bag->setFilePathTokens($tokens);
        $hint = $this->hintFromTokens($tokens);
        $bag->setFileNameHint($hint);

        if ($hint === 'pano') {
            $media->setIsPanorama(true);
        }

        $media->setFeatureBag($bag);

        return $media;
    }

    /** @param list<string> $tokens */
    private function hintFromTokens(array $tokens): string
    {
        foreach ($tokens as $t) {
            if (str_starts_with($t, 'pano')) {
                return 'pano';
            }

            if (str_starts_with($t, 'img_e')) {
                return 'edited';
            }

            if (str_contains($t, 'timelapse')) {
                return 'timelapse';
            }

            if (str_contains($t, 'slowmo') || str_contains($t, 'slo-mo')) {
                return 'slowmo';
            }
        }

        return 'normal';
    }
}
