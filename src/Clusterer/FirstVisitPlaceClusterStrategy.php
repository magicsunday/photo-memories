<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\Support\AbstractGeoCellClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detects the earliest visit session per geogrid cell (first time at this place).
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 83])]
final class FirstVisitPlaceClusterStrategy extends AbstractGeoCellClusterStrategy
{
    private readonly DateTimeZone $timezone;

    public function __construct(
        float $gridDegrees = 0.01,
        string $timezone = 'Europe/Berlin',
        private readonly int $minItemsPerDay = 4,
        private readonly int $minNights = 0,
        private readonly int $maxNights = 3,
        private readonly int $minItemsTotal = 8
    ) {
        if ($maxNights < $minNights) {
            throw new InvalidArgumentException('maxNights must be >= minNights.');
        }

        parent::__construct($gridDegrees);

        $this->timezone = new DateTimeZone($timezone);
    }

    public function name(): string
    {
        return 'first_visit_place';
    }

    protected function shouldConsider(Media $media): bool
    {
        return $media->getTakenAt() instanceof DateTimeImmutable;
    }

    /**
     * @param list<Media> $members
     * @return list<ClusterDraft>
     */
    protected function clustersForCell(string $cell, array $members): array
    {
        /** @var array<string, list<Media>> $byDay */
        $byDay = [];

        foreach ($members as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $day = $takenAt->setTimezone($this->timezone)->format('Y-m-d');
            $byDay[$day] ??= [];
            $byDay[$day][] = $media;
        }

        if ($byDay === []) {
            return [];
        }

        \ksort($byDay, \SORT_STRING);

        /** @var list<string> $runDays */
        $runDays = [];
        $prevDay = null;

        foreach ($byDay as $day => $list) {
            if (\count($list) < $this->minItemsPerDay) {
                $draft = $this->finalizeRun($cell, $runDays, $byDay);
                if ($draft !== null) {
                    return [$draft];
                }

                $runDays = [];
                $prevDay = null;
                continue;
            }

            if ($prevDay !== null && !$this->isNextDay($prevDay, $day)) {
                $draft = $this->finalizeRun($cell, $runDays, $byDay);
                if ($draft !== null) {
                    return [$draft];
                }

                $runDays = [];
            }

            $runDays[] = $day;
            $prevDay = $day;
        }

        $draft = $this->finalizeRun($cell, $runDays, $byDay);

        return $draft !== null ? [$draft] : [];
    }

    /**
     * @param list<string> $runDays
     * @param array<string, list<Media>> $byDay
     */
    private function finalizeRun(string $cell, array $runDays, array $byDay): ?ClusterDraft
    {
        if ($runDays === []) {
            return null;
        }

        $nights = \max(0, \count($runDays) - 1);
        if ($nights < $this->minNights || $nights > $this->maxNights) {
            return null;
        }

        /** @var list<Media> $members */
        $members = [];
        foreach ($runDays as $day) {
            foreach ($byDay[$day] as $media) {
                $members[] = $media;
            }
        }

        if (\count($members) < $this->minItemsTotal) {
            return null;
        }

        return $this->buildClusterDraft(
            $this->name(),
            $members,
            [
                'grid_cell' => $cell,
                'nights'    => $nights,
            ]
        );
    }

    private function isNextDay(string $a, string $b): bool
    {
        $ta = \strtotime($a . ' 00:00:00');
        $tb = \strtotime($b . ' 00:00:00');

        if ($ta === false || $tb === false) {
            return false;
        }

        return ($tb - $ta) === 86400;
    }
}
