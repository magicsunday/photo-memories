<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer;

use DateTimeImmutable;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Support\ClusterBuildHelperTrait;
use MagicSunday\Memories\Clusterer\Support\MediaFilterTrait;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\Calendar;
use MagicSunday\Memories\Utility\MediaMath;

use function array_map;
use function assert;
use function explode;

/**
 * Builds clusters for German (federal) holidays per year (no state-specific).
 * Simple exact-date grouping; minimal dependencies.
 */
final readonly class HolidayEventClusterStrategy implements ClusterStrategyInterface
{
    use ClusterBuildHelperTrait;
    use MediaFilterTrait;

    public function __construct(
        private int $minItemsPerHoliday = 8,
    ) {
        if ($this->minItemsPerHoliday < 1) {
            throw new InvalidArgumentException('minItemsPerHoliday must be >= 1.');
        }
    }

    public function name(): string
    {
        return 'holiday_event';
    }

    /**
     * @param list<Media> $items
     *
     * @return list<ClusterDraft>
     */
    public function cluster(array $items): array
    {
        /** @var list<Media> $timestamped */
        $timestamped = $this->filterTimestampedItems($items);

        /** @var array<string, list<Media>> $groups */
        $groups = [];

        foreach ($timestamped as $m) {
            $t = $m->getTakenAt();
            assert($t instanceof DateTimeImmutable);
            $features = $this->extractCalendarFeatures($m);

            $name = null;
            $year = null;

            if ($features['isHoliday'] === true) {
                $decoded = $this->decodeHolidayFeature($features['holidayId']);
                if ($decoded['code'] !== null) {
                    $name = $this->holidayLabelFromCode($decoded['code']);
                    $year = $decoded['year'];
                }
            }

            if ($name === null) {
                $name = Calendar::germanFederalHolidayName($t);
            }

            if ($name === null) {
                continue;
            }

            $holidayYear = $year ?? (int) $t->format('Y');

            $key = $holidayYear . ':' . $name . ':' . $t->format('Y-m-d');
            $groups[$key] ??= [];
            $groups[$key][] = $m;
        }

        /** @var array<string, list<Media>> $eligibleGroups */
        $eligibleGroups = $this->filterGroupsByMinItems($groups, $this->minItemsPerHoliday);

        /** @var list<ClusterDraft> $out */
        $out = [];

        foreach ($eligibleGroups as $key => $members) {
            [$yearStr, $name] = explode(':', $key, 3);
            $centroid         = MediaMath::centroid($members);
            $time             = MediaMath::timeRange($members);

            $out[] = new ClusterDraft(
                algorithm: $this->name(),
                params   : [
                    'year'         => (int) $yearStr,
                    'holiday'      => 1.0,
                    'holiday_name' => $name,
                    'time_range'   => $time,
                ],
                centroid: ['lat' => (float) $centroid['lat'], 'lon' => (float) $centroid['lon']],
                members: array_map(static fn (Media $m): int => $m->getId(), $members)
            );
        }

        return $out;
    }
}
