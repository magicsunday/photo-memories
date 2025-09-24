<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Entity\Media;
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
final class PhotoMotifClusterStrategy extends AbstractTimeGapClusterStrategy
{
    /** @var array<string, array{label: string, slug: string}> */
    private array $motifMeta = [];

    public function __construct(
        int $sessionGapSeconds = 48 * 3600, // split sessions after 48h gap
        int $minItems = 6,
        string $timezone = 'UTC'
    ) {
        parent::__construct($timezone, $sessionGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'photo_motif';
    }

    protected function beforeGrouping(): void
    {
        $this->motifMeta = [];
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        $mime = $media->getMime();

        return $media->getTakenAt() instanceof DateTimeImmutable
            && ($mime === null || \str_starts_with($mime, 'image/'));
    }

    protected function groupKey(Media $media, DateTimeImmutable $local): ?string
    {
        $motif = $this->detectMotif($media);
        if ($motif === null) {
            return null;
        }

        $key = $motif['slug'] . '|' . $motif['label'];
        $this->motifMeta[$key] = $motif;

        return $key;
    }

    protected function sessionParams(array $members): array
    {
        $key = $this->currentGroupKey();

        if ($key === null || !isset($this->motifMeta[$key])) {
            return [];
        }

        return [
            'motif' => $this->motifMeta[$key]['slug'],
            'label' => $this->motifMeta[$key]['label'],
        ];
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
