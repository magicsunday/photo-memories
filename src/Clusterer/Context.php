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
use MagicSunday\Memories\Clusterer\Support\ClusterDeviceMetadataAggregator;
use MagicSunday\Memories\Clusterer\Support\ClusterPeopleAggregator;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\S2CellId;

use function array_key_exists;
use function max;
use function min;
use function round;

/**
 * Immutable context payload shared with cluster strategies.
 */
final readonly class Context
{
    /**
     * @param array{from:int,to:int}|null                $timeWindow
     * @param string|null                                $locationCell
     * @param array{people: float, people_count: int, people_unique: int, people_coverage: float, people_face_coverage: float} $people
     * @param float|null                                 $deviceDiversity
     */
    public function __construct(
        private ?array $timeWindow,
        private ?string $locationCell,
        private array $people,
        private ?float $deviceDiversity,
    ) {
    }

    /**
     * @param list<Media> $scope
     */
    public static function fromScope(array $scope): self
    {
        return new self(
            self::computeTimeWindow($scope),
            self::computeLocationCell($scope),
            self::computePeopleMetrics($scope),
            self::computeDeviceDiversity($scope),
        );
    }

    /**
     * @return array{from:int,to:int}|null
     */
    public function timeWindow(): ?array
    {
        return $this->timeWindow;
    }

    public function locationCell(): ?string
    {
        return $this->locationCell;
    }

    /**
     * @return array{people: float, people_count: int, people_unique: int, people_coverage: float, people_face_coverage: float}
     */
    public function people(): array
    {
        return $this->people;
    }

    public function deviceDiversity(): ?float
    {
        return $this->deviceDiversity;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function augmentParams(array $params): array
    {
        if ($this->timeWindow !== null && !array_key_exists('context_time_window', $params)) {
            $params['context_time_window'] = $this->timeWindow;
        }

        if ($this->locationCell !== null && !array_key_exists('context_location_cell', $params)) {
            $params['context_location_cell'] = $this->locationCell;
        }

        if ($this->people !== []) {
            foreach ($this->people as $key => $value) {
                $contextKey = 'context_' . (string) $key;
                if (!array_key_exists($contextKey, $params)) {
                    $params[$contextKey] = $value;
                }
            }
        }

        if ($this->deviceDiversity !== null && !array_key_exists('context_device_diversity', $params)) {
            $params['context_device_diversity'] = $this->deviceDiversity;
        }

        return $params;
    }

    public function applyToDraft(ClusterDraft $draft): void
    {
        $params = $this->augmentParams($draft->getParams());

        foreach ($params as $key => $value) {
            $draft->setParam($key, $value);
        }
    }

    /**
     * @param list<Media> $scope
     *
     * @return array{from:int,to:int}|null
     */
    private static function computeTimeWindow(array $scope): ?array
    {
        $from = null;
        $to   = null;

        foreach ($scope as $media) {
            $takenAt = $media->getTakenAt();
            if (!$takenAt instanceof DateTimeImmutable) {
                continue;
            }

            $ts = $takenAt->getTimestamp();
            $from = $from === null ? $ts : min($from, $ts);
            $to   = $to === null ? $ts : max($to, $ts);
        }

        if ($from === null || $to === null) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param list<Media> $scope
     */
    private static function computeLocationCell(array $scope): ?string
    {
        $counts = [];

        foreach ($scope as $media) {
            $cell = $media->getS2CellId();
            if ($cell === null) {
                $lat = $media->getGpsLat();
                $lon = $media->getGpsLon();

                if ($lat === null || $lon === null) {
                    continue;
                }

                $cell = S2CellId::tokenFromDegrees((float) $lat, (float) $lon, 7);
            }

            $token = (string) $cell;
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        if ($counts === []) {
            return null;
        }

        $majorityCell = null;
        $majority     = 0;

        foreach ($counts as $cell => $count) {
            if ($count > $majority) {
                $majority     = $count;
                $majorityCell = $cell;
            }
        }

        return $majorityCell !== null ? (string) $majorityCell : null;
    }

    /**
     * @param list<Media> $scope
     *
     * @return array{people: float, people_count: int, people_unique: int, people_coverage: float, people_face_coverage: float}
     */
    private static function computePeopleMetrics(array $scope): array
    {
        static $aggregator = null;

        if (!$aggregator instanceof ClusterPeopleAggregator) {
            $aggregator = new ClusterPeopleAggregator();
        }

        return $aggregator->buildParams($scope);
    }

    /**
     * @param list<Media> $scope
     */
    private static function computeDeviceDiversity(array $scope): ?float
    {
        if ($scope === []) {
            return null;
        }

        static $aggregator = null;

        if (!$aggregator instanceof ClusterDeviceMetadataAggregator) {
            $aggregator = new ClusterDeviceMetadataAggregator();
        }

        $summary = $aggregator->summarize($scope);
        $variants = (int) ($summary['device_variants'] ?? 0);
        $share    = $summary['device_primary_share'] ?? null;

        if ($variants <= 1 || $share === null) {
            return null;
        }

        $share = max(0.0, min(1.0, (float) $share));

        return round(1.0 - $share, 4);
    }
}
