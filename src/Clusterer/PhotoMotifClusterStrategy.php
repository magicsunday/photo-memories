<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\MediaMath;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Heuristic photo motif clustering based on path/camera hints.
 * Groups photos into motif sessions (gap-based) to keep clusters compact.
 *
 * Motifs (label → slug):
 *  - Berge                → mountains
 *  - Strand & Meer        → beach_sea
 *  - See & Fluss          → lake_river
 *  - Wald & Natur         → forest_nature
 *  - Stadt                → city
 *  - Museum & Ausstellung → museum
 *  - Konzert & Bühne      → concert
 *  - Schnee & Winter      → snow_winter
 *  - Action & Outdoor     → action_outdoor
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 48])]
final class PhotoMotifClusterStrategy implements ClusterStrategyInterface
{
    public function __construct(
        private readonly int $sessionGapSeconds = 48 * 3600, // split sessions after 48h gap
        private readonly int $minItems = 6
    ) {
    }

    public function name(): string
    {
        return 'photo_motif';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        // Filter: need takenAt; images only (if mime present)
        $pool = \array_values(\array_filter($items, static function (Media $m): bool {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                return false;
            }
            $mime = $m->getMime();
            return $mime === null || \str_starts_with($mime, 'image/');
        }));

        if (\count($pool) < $this->minItems) {
            return [];
        }

        // Assign motif per media
        /** @var array<string, list<Media>> $byMotif */
        $byMotif = [];
        foreach ($pool as $m) {
            $motif = $this->detectMotif($m);
            if ($motif === null) {
                continue;
            }
            $key = $motif['slug'] . '|' . $motif['label'];
            $byMotif[$key] ??= [];
            $byMotif[$key][] = $m;
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        // For each motif, form time sessions
        foreach ($byMotif as $key => $list) {
            if (\count($list) < $this->minItems) {
                continue;
            }

            \usort($list, static fn (Media $a, Media $b): int => $a->getTakenAt() <=> $b->getTakenAt());

            /** @var list<Media> $buf */
            $buf = [];
            $lastTs = null;

            $flush = function () use (&$buf, &$out, $key): void {
                if (\count($buf) < $this->minItems) {
                    $buf = [];
                    return;
                }

                [$slug, $label] = \explode('|', $key, 2);
                $gps = \array_values(\array_filter($buf, static fn (Media $m): bool => $m->getGpsLat() !== null && $m->getGpsLon() !== null));
                $centroid = $gps !== [] ? MediaMath::centroid($gps) : ['lat' => 0.0, 'lon' => 0.0];
                $time = MediaMath::timeRange($buf);

                $out[] = new ClusterDraft(
                    algorithm: 'photo_motif',
                    params: [
                        'label'      => $label,
                        'motif'      => $slug,
                        'time_range' => $time,
                    ],
                    centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                    members: \array_map(static fn (Media $m): int => $m->getId(), $buf)
                );

                $buf = [];
            };

            foreach ($list as $m) {
                $ts = (int) $m->getTakenAt()->getTimestamp();
                if ($lastTs !== null && ($ts - $lastTs) > $this->sessionGapSeconds) {
                    $flush();
                }
                $buf[] = $m;
                $lastTs = $ts;
            }
            $flush();
        }

        return $out;
    }

    /**
     * Decide on a single motif using path/camera keywords.
     *
     * @return array{label:string, slug:string}|null
     */
    private function detectMotif(Media $m): ?array
    {
        $path = \strtolower($m->getPath());
        $model = \strtolower((string) ($m->getCameraModel() ?? ''));

        // Ordered rules (first match wins)
        /** @var list<array{pattern:string,label:string,slug:string}> $rules */
        $rules = [
            // Mountains / hiking / alpine terms
            ['pattern' => '/\b(berg|berge|alpen|dolomiti|dolomites|mountain|gipfel|pass|wander(n|ung)?|trail|hike)\b/u', 'label' => 'Berge', 'slug' => 'mountains'],

            // Beach & sea / coast
            ['pattern' => '/\b(strand|beach|meer|ocean|küste|kueste|coast|seaside|ostsee|nordsee|adriatic|baltic)\b/u', 'label' => 'Strand & Meer', 'slug' => 'beach_sea'],

            // Lakes & rivers
            ['pattern' => '/\b(see|lake|fluss|river|ufer)\b/u', 'label' => 'See & Fluss', 'slug' => 'lake_river'],

            // Forest & nature / parks
            ['pattern' => '/\b(wald|forest|park|nationalpark|nature|garten|woods|baum|trees?)\b/u', 'label' => 'Wald & Natur', 'slug' => 'forest_nature'],

            // City / skyline / urban
            ['pattern' => '/\b(stadt|city|urban|downtown|skyline|skyscraper|street)\b/u', 'label' => 'Stadt', 'slug' => 'city'],

            // Museums / exhibitions
            ['pattern' => '/\b(museum|galerie|gallery|ausstellung|exhibit|exhibition)\b/u', 'label' => 'Museum & Ausstellung', 'slug' => 'museum'],

            // Concerts / festivals / stage
            ['pattern' => '/\b(konzert|concert|festival|gig|bühne|buehne|stage|live)\b/u', 'label' => 'Konzert & Bühne', 'slug' => 'concert'],

            // Snow / skiing
            ['pattern' => '/\b(schnee|snow|ski|skitour|langlauf|snowboard|piste)\b/u', 'label' => 'Schnee & Winter', 'slug' => 'snow_winter'],
        ];

        foreach ($rules as $r) {
            if (\preg_match($r['pattern'], $path) === 1) {
                return ['label' => $r['label'], 'slug' => $r['slug']];
            }
        }

        // Camera hints → action/outdoor
        if ($model !== '' && (\str_contains($model, 'gopro') || \str_contains($model, 'dji'))) {
            return ['label' => 'Action & Outdoor', 'slug' => 'action_outdoor'];
        }

        return null;
    }
}
