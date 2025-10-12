<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Selection\Support;

/**
 * Normalises and evaluates simple face-detection related metrics.
 */
final class FaceMetricHelper
{
    public const GROUP_FACE_COUNT_THRESHOLD = 3;
    public const GROUP_FACE_MAX_DOMINANCE = 0.45;
    public const CLOSE_UP_DOMINANCE_THRESHOLD = 0.55;

    private function __construct()
    {
    }

    public static function normaliseCoverage(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    public static function groupBonusScale(int $faceCount): float
    {
        if ($faceCount < self::GROUP_FACE_COUNT_THRESHOLD) {
            return 0.0;
        }

        $excess = $faceCount - self::GROUP_FACE_COUNT_THRESHOLD;
        if ($excess <= 0) {
            return 0.25;
        }

        $clamped = $excess > 3 ? 3 : $excess;

        return 0.25 + ($clamped * 0.25);
    }

    public static function closeUpPenaltyFactor(?float $largestCoverage): float
    {
        $coverage = self::normaliseCoverage($largestCoverage);
        if ($coverage === null) {
            return 0.0;
        }

        if ($coverage < self::CLOSE_UP_DOMINANCE_THRESHOLD) {
            return 0.0;
        }

        $range = 1.0 - self::CLOSE_UP_DOMINANCE_THRESHOLD;
        if ($range <= 0.0) {
            return 1.0;
        }

        $excess = $coverage - self::CLOSE_UP_DOMINANCE_THRESHOLD;
        if ($excess <= 0.0) {
            return 0.0;
        }

        $factor = $excess / $range;

        return $factor > 1.0 ? 1.0 : $factor;
    }

    public static function isGroupShot(int $faceCount, ?float $largestCoverage = null): bool
    {
        if ($faceCount < self::GROUP_FACE_COUNT_THRESHOLD) {
            return false;
        }

        $coverage = self::normaliseCoverage($largestCoverage);
        if ($coverage !== null && $coverage > self::GROUP_FACE_MAX_DOMINANCE) {
            return false;
        }

        return true;
    }

    public static function isDominantCloseUp(?float $largestCoverage): bool
    {
        return self::closeUpPenaltyFactor($largestCoverage) > 0.0;
    }
}
