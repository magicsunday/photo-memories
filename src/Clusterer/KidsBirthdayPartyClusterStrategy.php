<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Detects kids' birthday parties based on keywords; compact time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 72])]
final class KidsBirthdayPartyClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $sessionGapSeconds = 3 * 3600,
        private readonly float $radiusMeters = 250.0,
        private readonly int $minItems = 6
    ) {
    }

    public function name(): string
    {
        return 'kids_birthday_party';
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
            $h = (int) $t->setTimezone($tz)->format('G'); // prefer 10–21
            if ($h < 10 || $h > 21) {
                continue;
            }
            $path = \strtolower($m->getPath());
            if ($this->looksBirthday($path)) {
                $cand[] = $m;
            }
        }

        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        /** @var list<ClusterDraft> $out */
        $out = [];
        /** @var list<Media> $buf */
        $buf = [];
        $last = null;

        $flush = function () use (&$buf, &$out): void {
            if (\count($buf) < $this->minItems) {
                $buf = [];
                return;
            }
            // spatial compactness (if GPS exists)
            $gps = \array_values(\array_filter($buf, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];

            $ok = true;
            foreach ($gps as $m) {
                $d = MediaMath::haversineDistanceInMeters(
                    (float) $centroid['lat'], (float) $centroid['lon'],
                    (float) $m->getGpsLat(), (float) $m->getGpsLon()
                );
                if ($d > $this->radiusMeters) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                $buf = [];
                return;
            }

            $time = MediaMath::timeRange($buf);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params: [
                    'time_range' => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: \array_map(static fn (Media $m): int => $m->getId(), $buf)
            );
            $buf = [];
        };

        foreach ($cand as $m) {
            $ts = $m->getTakenAt()?->getTimestamp();
            if ($ts === null) {
                continue;
            }
            if ($last !== null && ($ts - $last) > $this->sessionGapSeconds) {
                $flush();
            }
            $buf[] = $m;
            $last = $ts;
        }
        $flush();

        return $out;
    }

    private function looksBirthday(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = ['geburtstag', 'birthday', 'party', 'kinder', 'kids', 'kerzen', 'torte', 'kuchen', 'luftballon', 'balloon', 'geschenke'];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
