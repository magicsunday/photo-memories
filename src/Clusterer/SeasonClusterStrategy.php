<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\ProgressAwareClusterStrategyInterface;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterLocationMetadataTrait;
use MagicSunday\Memories\Clusterer\Support\ClusterQualityAggregator;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Clusterer\Support\ProgressAwareClusterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\CalendarFeatureHelper;
use MagicSunday\Memories\Utility\LocationHelper;

use function assert;
use function count;
use function explode;
use function intdiv;
use function mb_strtolower;
use function max;
use function sprintf;

/**
 * Groups media by meteorological seasons per year (DE).
 * Winter is Dec–Feb (December assigned to next year).
 */
final readonly class SeasonClusterStrategy implements ClusterStrategyInterface, ProgressAwareClusterStrategyInterface
{
    use MediaFilterTrait;
    use ClusterBuildHelperTrait;
    use ClusterLocationMetadataTrait;
    use ProgressAwareClusterTrait;

    private const PROGRESS_SEGMENTS = 25;

    private ClusterQualityAggregator $qualityAggregator;

    private LocationHelper $locationHelper;

    public function __construct(
        LocationHelper $locationHelper,
        // Minimum members per (season, year) bucket.
        private int $minItemsPerSeason = 20,
        ?ClusterQualityAggregator $qualityAggregator = null,
    ) {
        if ($this->minItemsPerSeason < 1) {
            throw new InvalidArgumentException('minItemsPerSeason must be >= 1.');
        }

        $this->locationHelper    = $locationHelper;
        $this->qualityAggregator = $qualityAggregator ?? new ClusterQualityAggregator();
    }

    public function name(): string
    {
        return 'season';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        return $this->clusterInternal($items, null);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveSeasonAndYear(Media $media): array
    {
        $takenAt = $media->getTakenAt();
        assert($takenAt instanceof DateTimeImmutable);

        $month = (int) $takenAt->format('n');
        $year  = (int) $takenAt->format('Y');

        $calendarFeatures = CalendarFeatureHelper::extract($media);
        $seasonLabel      = $this->normaliseSeason($calendarFeatures['season']);

        if ($seasonLabel !== null) {
            if ($this->isWinterSeason($calendarFeatures['season']) && $month === 12) {
                ++$year;
            }

            return [$seasonLabel, $year];
        }

        $seasonLabel = match (true) {
            $month >= 3 && $month <= 5  => 'Frühling',
            $month >= 6 && $month <= 8  => 'Sommer',
            $month >= 9 && $month <= 11 => 'Herbst',
            default                     => 'Winter',
        };

        if ($seasonLabel === 'Winter' && $month === 12) {
            ++$year;
        }

        return [$seasonLabel, $year];
    }

    private function normaliseSeason(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower($value);

        return match ($normalized) {
            'winter' => 'Winter',
            'spring', 'frühling' => 'Frühling',
            'summer', 'sommer' => 'Sommer',
            'autumn', 'fall', 'herbst' => 'Herbst',
            default => null,
        };
    }

    private function isWinterSeason(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return mb_strtolower($value) === 'winter';
    }
    /**
     * @param list<Media>                                 $items
     * @param callable(int $done, int $max, string $stage):void $update
     *
     * @return list<ClusterDraft>
     */
    public function clusterWithProgress(array $items, callable $update): array
    {
        return $this->clusterInternal($items, $update);
    }

    /**
     * @param list<Media> $items
     * @param callable(int $done, int $max, string $stage):void|null $update
     *
     * @return list<ClusterDraft>
     */
    private function clusterInternal(array $items, ?callable $update): array
    {
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);
        $timestampedCount = count($timestamped);
        $maxSteps         = max(1, $timestampedCount);

        $this->notifyProgress($update, 0, $maxSteps, sprintf('Filtern (%d)', $timestampedCount));

        if ($timestamped === []) {
            $this->notifyProgress($update, $maxSteps, $maxSteps, 'Scoring & Metadaten');
            $this->notifyProgress($update, $maxSteps, $maxSteps, 'Abgeschlossen (0 Memories)');

            return [];
        }

        [$eligibleGroups, $processed] = $this->groupMembers($timestamped, $update, $maxSteps);

        $this->notifyProgress(
            $update,
            max($processed, max(1, $maxSteps - 1)),
            $maxSteps,
            'Scoring & Metadaten',
        );

        $drafts = $this->buildClustersFromGroups($eligibleGroups);

        $this->notifyProgress(
            $update,
            $maxSteps,
            $maxSteps,
            sprintf('Abgeschlossen (%d Memories)', count($drafts)),
        );

        return $drafts;
    }

    /**
     * @param list<Media>                                 $timestamped
     * @param callable(int $done, int $max, string $stage):void|null $update
     *
     * @return array{0: array<string, list<Media>>, 1: int}
     */
    private function groupMembers(array $timestamped, ?callable $update, int $maxSteps): array
    {
        /** @var array<string, list<Media>> $groups */
        $groups    = [];
        $processed = 0;
        $interval  = $this->progressInterval($maxSteps);

        foreach ($timestamped as $media) {
            [$season, $year] = $this->resolveSeasonAndYear($media);

            $key = $year . ':' . $season;
            $groups[$key] ??= [];
            $groups[$key][] = $media;

            ++$processed;

            if ($update !== null && ($processed % $interval === 0 || $processed === $maxSteps)) {
                $this->notifyProgress(
                    $update,
                    $processed,
                    $maxSteps,
                    sprintf('Gruppiere (%d/%d)', $processed, $maxSteps),
                );
            }
        }

        /** @var array<string, list<Media>> $eligibleGroups */
        $eligibleGroups = $this->filterGroupsByMinItems($groups, $this->minItemsPerSeason);

        return [$eligibleGroups, $processed];
    }

    private function progressInterval(int $maxSteps): int
    {
        return max(1, intdiv($maxSteps + self::PROGRESS_SEGMENTS - 1, self::PROGRESS_SEGMENTS));
    }

    /**
     * @param array<string, list<Media>> $eligibleGroups
     *
     * @return list<ClusterDraft>
     */
    private function buildClustersFromGroups(array $eligibleGroups): array
    {
        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleGroups as $key => $members) {
            [$yearStr, $season] = explode(':', $key, 2);
            $yearInt            = (int) $yearStr;

            $centroid = $this->computeCentroid($members);
            $time     = $this->computeTimeRange($members);

            $params = [
                'label'      => $season,
                'year'       => $yearInt,
                'time_range' => $time,
            ];

            $qualityParams = $this->qualityAggregator->buildParams($members);
            foreach ($qualityParams as $qualityKey => $qualityValue) {
                if ($qualityValue !== null) {
                    $params[$qualityKey] = $qualityValue;
                }
            }

            $tags = $this->collectDominantTags($members);
            if ($tags !== []) {
                $params = [...$params, ...$tags];
            }

            $params = $this->appendLocationMetadata($members, $params);

            $peopleParams = $this->buildPeopleParams($members);
            $params       = [...$params, ...$peopleParams];

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: $params,
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: $this->toMemberIds($members)
            );
        }

        return $out;
    }

}
