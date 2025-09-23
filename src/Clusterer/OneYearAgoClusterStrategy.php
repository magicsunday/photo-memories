<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds a memory around the same date last year within a +/- window.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 65])]
final class OneYearAgoClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;

    public function __construct(
        private readonly string $timezone = 'Europe/Berlin',
        private readonly int $windowDays = 3,
        private readonly int $minItems   = 8
    ) {
    }

    public function name(): string
    {
        return 'one_year_ago';
    }

    /**
     * @param list<Media> $items
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        $tz = new DateTimeZone($this->timezone);
        $now = new DateTimeImmutable('now', $tz);
        $anchorStart = $now->sub(new DateInterval('P1Y'))->modify('-' . $this->windowDays . ' days');
        $anchorEnd   = $now->sub(new DateInterval('P1Y'))->modify('+' . $this->windowDays . ' days');

        /** @var list<Media> $picked */
        $picked = [];

        foreach ($items as $m) {
            $t = $m->getTakenAt();
            if (!$t instanceof DateTimeImmutable) {
                continue;
            }
            $local = $t->setTimezone($tz);
            if ($local >= $anchorStart && $local <= $anchorEnd) {
                $picked[] = $m;
            }
        }

        if (\count($picked) < $this->minItems) {
            return [];
        }

        return [
            $this->buildClusterDraft($this->name(), $picked, []),
        ];
    }
}
