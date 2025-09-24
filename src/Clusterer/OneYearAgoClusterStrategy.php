<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateInterval;
use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimezoneAwareGroupedClusterStrategy;
use MagicSunday\Memories\Entity\Media;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Builds a memory around the same date last year within a +/- window.
 */
#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 65])]
final class OneYearAgoClusterStrategy extends AbstractTimezoneAwareGroupedClusterStrategy
{
    private DateTimeImmutable $windowStart;

    private DateTimeImmutable $windowEnd;

    public function __construct(
        string $timezone = 'Europe/Berlin',
        private readonly int $windowDays = 3,
        private readonly int $minItems = 8
    ) {
        parent::__construct($timezone);
        $this->windowStart = new DateTimeImmutable('@0');
        $this->windowEnd = new DateTimeImmutable('@0');
    }

    public function name(): string
    {
        return 'one_year_ago';
    }

    protected function beforeGrouping(): void
    {
        $now = new DateTimeImmutable('now', $this->timezone());
        $anchor = $now->sub(new DateInterval('P1Y'));
        $this->windowStart = $anchor->modify('-' . $this->windowDays . ' days');
        $this->windowEnd = $anchor->modify('+' . $this->windowDays . ' days');
    }

    protected function localGroupKey(Media $media, DateTimeImmutable $local): ?string
    {
        if ($local < $this->windowStart || $local > $this->windowEnd) {
            return null;
        }

        return 'one_year_ago';
    }

    /**
     * @param list<Media> $members
     */
    protected function groupParams(string $key, array $members): ?array
    {
        if (\count($members) < $this->minItems) {
            return null;
        }

        return [];
    }
}
