<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\TimeGapSplitterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Heuristic "Golden Hour" clusters around morning/evening hours.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 57])]
final class GoldenHourClusterStrategy implements ClusterStrategyInterface
{
    use TimeGapSplitterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        /** Inclusive local hours considered golden-hour candidates. */
        private readonly array $morningHours = [6, 7, 8],
        private readonly array $eveningHours = [18, 19, 20],
        private readonly int $sessionGapSeconds = 90 * 60,
        private readonly int $minItems = 5
    ) {
    }

    public function name(): string
    {
        return 'golden_hour';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);

        /** @var list<Media> $cand */
        $cand = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $h = (int) $t->setTimezone($tz)->format('G'); // 0..23
            if (\in_array($h, $this->morningHours, true) || \in_array($h, $this->eveningHours, true)) {
                $cand[] = $m;
            }
        }

        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

        $sessions = $this->splitIntoTimeGapSessions($cand, $this->sessionGapSeconds, $this->minItems);

        /** @var list<ClusterDraft> $out */
        $out = [];
        foreach ($sessions as $session) {
            $centroid = MediaMath::centroid($session);
            $time     = MediaMath::timeRange($session);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $session)
            );
        }

        return $out;
    }
}
