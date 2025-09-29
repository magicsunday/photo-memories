<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use MagicSunday\Memories\Entity\Media;

use function abs;
use function count;
use function floor;
use function max;
use function min;
use function sort;

use const SORT_NUMERIC;

/**
 * Heuristic cover picker:
 * - prefers landscape
 * - higher megapixels
 * - aspect close to 16:9 or 4:3
 * - timestamp near median
 * - has thumbnails
 */
final class DefaultCoverPicker implements CoverPickerInterface
{
    public function pickCover(array $members, array $clusterParams): ?Media
    {
        if ($members === []) {
            return null;
        }

        // Median timestamp (for temporal proximity)
        $ts = [];
        foreach ($members as $m) {
            $t = $m->getTakenAt()?->getTimestamp() ?? null;
            if ($t !== null) {
                $ts[] = $t;
            }
        }

        sort($ts, SORT_NUMERIC);
        $medianTs = $ts !== [] ? $ts[(int) floor(count($ts) / 2)] : null;

        $best      = null;
        $bestScore = -1.0;

        foreach ($members as $m) {
            $score = $this->score($m, $medianTs);
            if ($score > $bestScore) {
                $best      = $m;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function score(Media $m, ?int $medianTs): float
    {
        $w      = $m->getWidth() ?? 0;
        $h      = $m->getHeight() ?? 0;
        $areaMp = $w > 0 && $h > 0 ? (($w * $h) / 1_000_000.0) : 0.0;

        // Orientation bonus
        $landscape = ($w >= $h) ? 1.0 : 0.0;

        // Aspect closeness (to 16:9 or 4:3)
        $ratio       = ($h > 0) ? ($w / (float) $h) : 0.0;
        $d169        = abs($ratio - (16.0 / 9.0));
        $d43         = abs($ratio - (4.0 / 3.0));
        $aspectScore = 1.0 - min(1.0, min($d169, $d43) / 1.0);

        // Timestamp proximity to median
        $t         = $m->getTakenAt()?->getTimestamp();
        $timeScore = 0.5;
        if ($medianTs !== null && $t !== null) {
            $delta     = abs($t - $medianTs) / 3600.0; // hours
            $timeScore = max(0.0, 1.0 - min(1.0, $delta / 12.0)); // within ±12h → high
        }

        // Thumbnails present?
        $thumbBonus = ($m->getThumbnails() !== null && $m->getThumbnails() !== []) ? 0.1 : 0.0;

        // File size hints
        $sizeScore = min(1.0, (float) $m->getSize() / 8_000_000.0); // cap @ ~8MB

        // Combine (weights tuned pragmatisch)
        return
            0.35 * min(1.0, $areaMp / 12.0) +  // up to ~12MP normalized
            0.20 * $landscape +
            0.15 * $aspectScore +
            0.15 * $timeScore +
            0.10 * $sizeScore +
            0.05 * $thumbBonus;
    }
}
