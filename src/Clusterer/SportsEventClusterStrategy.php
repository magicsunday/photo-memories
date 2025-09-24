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
 * Sports events based on keywords (stadium/match/club names) and weekend bias.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 78])]
final class SportsEventClusterStrategy implements ClusterStrategyInterface
{
    use TimeGapSplitterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $sessionGapSeconds = 3 * 3600,
        private readonly float $radiusMeters = 500.0,
        private readonly int $minItems = 5,
        private readonly bool $preferWeekend = true
    ) {
    }

    public function name(): string
    {
        return 'sports_event';
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
            $path = \strtolower($m->getPath());
            if (!$this->looksSporty($path)) {
                continue;
            }
            if ($this->preferWeekend) {
                $dow = (int) $t->setTimezone($tz)->format('N'); // 1..7
                if ($dow !== 6 && $dow !== 7) {
                    // still allow, but you could continue; here we keep it lenient
                }
            }
            $cand[] = $m;
        }

        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn(Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        $sessions = $this->splitIntoTimeGapSessions($cand, $this->sessionGapSeconds, $this->minItems);

        /** @var list<ClusterDraft> $out */
        $out = [];
        foreach ($sessions as $session) {
            $gps = \array_values(\array_filter($session, static fn(Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];

            $ok = true;
            foreach ($gps as $m) {
                $d = MediaMath::haversineDistanceInMeters(
                    (float)$centroid['lat'], (float)$centroid['lon'],
                    (float)$m->getGpsLat(), (float)$m->getGpsLon()
                );
                if ($d > $this->radiusMeters) {
                    $ok = false;
                    break;
                }
            }
            if ($ok === false) {
                continue;
            }

            $time = MediaMath::timeRange($session);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float)$centroid['lat'], 'lon' => (float)$centroid['lon']],
                members: \array_map(static fn(Media $m): int => $m->getId(), $session)
            );
        }

        return $out;
    }

    private function looksSporty(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = [
            'stadion', 'arena', 'sportpark', 'eishalle',
            'match', 'spiel', 'game', 'derby',
            'fussball', 'fußball', 'football', 'soccer',
            'handball', 'basketball', 'eishockey', 'hockey',
            'tennis', 'marathon', 'lauf', 'run', 'triathlon',
            'bundesliga', 'dfb', 'uefa', 'champions', 'cup'
        ];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
