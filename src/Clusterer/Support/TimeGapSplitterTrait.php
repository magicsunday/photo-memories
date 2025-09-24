<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Support;

use MagicSunday\Memories\Entity\Media;

/**
 * Helper trait to split media into sessions separated by a time gap.
 */
trait TimeGapSplitterTrait
{
    /**
     * Split already sorted media into sessions separated by the given gap.
     *
     * @param list<Media> $items Sorted in ascending order by the taken-at timestamp.
     * @return list<list<Media>>
     */
    private function splitIntoTimeGapSessions(array $items, int $sessionGapSeconds, int $minItems): array
    {
        if ($items === []) {
            return [];
        }

        /** @var list<list<Media>> $sessions */
        $sessions = [];
        /** @var list<Media> $buffer */
        $buffer = [];
        $lastTimestamp = null;

        foreach ($items as $media) {
            $timestamp = $media->getTakenAt()?->getTimestamp();
            if ($timestamp === null) {
                continue;
            }

            if ($lastTimestamp !== null && ($timestamp - $lastTimestamp) > $sessionGapSeconds) {
                if (\count($buffer) >= $minItems) {
                    $sessions[] = $buffer;
                }
                $buffer = [];
            }

            $buffer[] = $media;
            $lastTimestamp = $timestamp;
        }

        if (\count($buffer) >= $minItems) {
            $sessions[] = $buffer;
        }

        return $sessions;
    }
}
