<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use MagicSunday\Memories\Clusterer\Support\TimeGapSplitterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Heuristic pet moments based on path keywords; grouped into time sessions.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 49])]
final class PetMomentsClusterStrategy implements ClusterStrategyInterface
{
    use TimeGapSplitterTrait;

    public function __construct(
        private readonly int $sessionGapSeconds = 2 * 3600,
        private readonly int $minItems = 6
    ) {
    }

    public function name(): string
    {
        return 'pet_moments';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $cand */
        $cand = [];
        foreach ($items as $m) {
            $path = \strtolower($m->getPath());
            if ($this->looksLikePet($path)) {
                $cand[] = $m;
            }
        }
        if (\count($cand) < $this->minItems) {
            return [];
        }

        \usort($cand, static fn (Media $a, Media $b): int =>
            ($a->getTakenAt()?->getTimestamp() ?? 0) <=> ($b->getTakenAt()?->getTimestamp() ?? 0)
        );

        $sessions = $this->splitIntoTimeGapSessions($cand, $this->sessionGapSeconds, $this->minItems);

        /** @var list<ClusterDraft> $out */
        $out = [];
        foreach ($sessions as $session) {
            $gps = \array_values(\array_filter($session, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
            $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];
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

    private function looksLikePet(string $pathLower): bool
    {
        /** @var list<string> $kw */
        $kw = [
            'dog', 'dogs', 'hund', 'hunde', 'welpe', 'puppy',
            'cat', 'cats', 'katze', 'kater', 'kitten',
            'hamster', 'kaninchen', 'bunny', 'rabbit',
            'meerschwein', 'guinea', 'pony', 'pferd',
            'haustier', 'pet', 'tierpark', 'zoo',
        ];
        foreach ($kw as $k) {
            if (\str_contains($pathLower, $k)) {
                return true;
            }
        }
        return false;
    }
}
