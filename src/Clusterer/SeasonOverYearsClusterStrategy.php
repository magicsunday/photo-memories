<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aggregates each season across multiple years into a memory
 * (e.g., "Sommer im Laufe der Jahre").
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 62])]
final class SeasonOverYearsClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly int $minYears = 3,
        private readonly int $minItems = 30
    ) {
    }

    public function name(): string
    {
        return 'season_over_years';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var array<string, list<Media>> $groups */
        $groups = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $month = (int) $t->format('n');
            $season = match (true) {
                $month >= 3 && $month <= 5  => 'Frühling',
                $month >= 6 && $month <= 8  => 'Sommer',
                $month >= 9 && $month <= 11 => 'Herbst',
                default => 'Winter',
            };
            $groups[$season] ??= [];
            $groups[$season][] = $m;
        }

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($groups as $season => $list) {
            if (\count($list) < $this->minItems) {
                continue;
            }
            /** @var array<int,bool> $years */
            $years = [];
            foreach ($list as $m) {
                $years[(int) $m->getTakenAt()->format('Y')] = true;
            }
            if (\count($years) < $this->minYears) {
                continue;
            }

            $out[] = $this->buildClusterDraft(
                algorithm: $this->name(),
                members: $list,
                params: [
                    'label' => $season . ' im Laufe der Jahre',
                    'years' => \array_values(\array_keys($years)),
                ]
            );
        }

        return $out;
    }
}
