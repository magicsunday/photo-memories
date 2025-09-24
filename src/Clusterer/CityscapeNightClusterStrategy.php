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
 * City night sessions: night hours & urban keywords, spatially compact.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 76])]
final class CityscapeNightClusterStrategy implements ClusterStrategyInterface
{
    use TimeGapSplitterTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $sessionGapSeconds = 2 * 3600,
        private readonly float $radiusMeters = 350.0,
        private readonly int $minItems = 5
    ) {
    }

    public function name(): string
    {
        return 'cityscape_night';
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
            $h = (int) $t->setTimezone($tz)->format('G');
            $night = ($h >= 20 || $h <= 2);
            if ($night === false) {
                continue;
            }
            $path = \strtolower($m->getPath());
            if (!$this->looksUrban($path)) {
                continue;
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

    private function looksUrban(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['city', 'urban', 'downtown', 'skyline', 'hochhaus', 'skyscraper', 'street', 'straße', 'strasse', 'platz'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
