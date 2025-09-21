<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use MagicSunday\Memories\Clusterer\ClusterDraft;

/**
 * Contract for generating human-readable titles/subtitles for clusters.
 */
interface TitleGeneratorInterface
{
    /**
     * Returns a short, human-friendly title in German (iOS-like).
     */
    public function makeTitle(ClusterDraft $cluster): string;

    /**
     * Returns a concise subtitle (usually date span or context).
     */
    public function makeSubtitle(ClusterDraft $cluster): string;
}
