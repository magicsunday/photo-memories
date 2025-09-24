<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\Support\AbstractTimeGapClusterStrategy;
use MagicSunday\Memories\Clusterer\Support\PlaceLabelHelperTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\LocationHelper;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('memories.cluster_strategy', attributes: ['priority' => 50])]
final class TimeSimilarityStrategy extends AbstractTimeGapClusterStrategy
{
    use PlaceLabelHelperTrait;

    private ?string $lastLocalityKey = null;

    public function __construct(
        private readonly LocationHelper $locHelper,
        int $maxGapSeconds = 21600,
        int $minItems = 5,
        string $timezone = 'Europe/Berlin'
    ) {
        parent::__construct($timezone, $maxGapSeconds, $minItems);
    }

    public function name(): string
    {
        return 'time_similarity';
    }

    protected function shouldConsider(Media $media, DateTimeImmutable $local): bool
    {
        return $media->getTakenAt() instanceof DateTimeImmutable;
    }

    protected function shouldSplitSession(Media $previous, Media $current, int $gapSeconds): bool
    {
        $prevKey = $this->resolveLocalityKey($previous);
        $currentKey = $this->locHelper->localityKeyForMedia($current);

        if ($currentKey !== null) {
            if ($prevKey !== null && $currentKey !== $prevKey) {
                $this->lastLocalityKey = $currentKey;
                return true;
            }

            $this->lastLocalityKey = $currentKey;
        } elseif ($prevKey !== null) {
            $this->lastLocalityKey = $prevKey;
        }

        return false;
    }

    protected function onMediaAppended(Media $media): void
    {
        $key = $this->locHelper->localityKeyForMedia($media);
        if ($key !== null) {
            $this->lastLocalityKey = $key;
        }
    }

    protected function onSessionReset(): void
    {
        $this->lastLocalityKey = null;
    }

    protected function sessionParams(array $members): array
    {
        return $this->withMajorityPlace($members);
    }

    private function resolveLocalityKey(Media $media): ?string
    {
        $key = $this->locHelper->localityKeyForMedia($media);
        if ($key !== null) {
            $this->lastLocalityKey = $key;
            return $key;
        }

        return $this->lastLocalityKey;
    }
}
