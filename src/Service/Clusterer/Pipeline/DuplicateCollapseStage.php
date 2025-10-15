<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer\Pipeline;

use DateTimeImmutable;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidationStageInterface;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;

use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function sort;
use function sprintf;
use function substr;

/**
 * Removes exact duplicates based on normalized member fingerprints.
 */
final class DuplicateCollapseStage implements ClusterConsolidationStageInterface
{
    use StageSupportTrait;

    /** @var array<string,int> */
    private array $priorityMap = [];

    /**
     * @param list<string> $keepOrder
     */
    public function __construct(array $keepOrder, private readonly ?JobMonitoringEmitterInterface $monitoringEmitter = null)
    {
        $base = count($keepOrder);
        foreach ($keepOrder as $index => $algorithm) {
            $this->priorityMap[$algorithm] = $base - $index;
        }
    }

    public function getLabel(): string
    {
        return 'Exakte Duplikate';
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<ClusterDraft>
     */
    public function process(array $drafts, ?callable $progress = null): array
    {
        $total = count($drafts);

        $this->emitMonitoring('selection_start', [
            'pre_count'             => $total,
            'priority_map_size'     => count($this->priorityMap),
            'configured_algorithms' => array_keys($this->priorityMap),
        ]);

        if ($total <= 1) {
            if ($progress !== null) {
                $progress($total, $total);
            }

            $this->emitMonitoring('selection_completed', [
                'pre_count'         => $total,
                'post_count'        => $total,
                'dropped_duplicates'=> 0,
                'dropped_count'     => 0,
            ]);

            return $drafts;
        }

        if ($progress !== null) {
            $progress(0, $total);
        }

        /** @var list<list<int>> $normalized */
        $normalized = array_map(
            fn (ClusterDraft $draft): array => $this->normalizeMembers($draft->getMembers()),
            $drafts,
        );

        /** @var list<string> $compositeFingerprints */
        $compositeFingerprints = [];
        foreach ($drafts as $index => $draft) {
            $compositeFingerprints[$index] = $this->buildCompositeFingerprint($draft, $normalized[$index]);
        }

        /** @var array<string,int> $winnerByFingerprint */
        $winnerByFingerprint = [];
        foreach ($drafts as $index => $draft) {
            if ($progress !== null && ($index % 400) === 0) {
                $progress($index, $total);
            }

            $fingerprint = $compositeFingerprints[$index];
            $current     = $winnerByFingerprint[$fingerprint] ?? null;
            if ($current === null) {
                $winnerByFingerprint[$fingerprint] = $index;
                continue;
            }

            if ($this->isBetter($draft, $normalized[$index], $drafts[$current], $normalized[$current])) {
                $winnerByFingerprint[$fingerprint] = $index;
            }
        }

        if ($progress !== null) {
            $progress($total, $total);
        }

        /** @var list<int> $winners */
        $winners = array_values($winnerByFingerprint);

        /** @var list<ClusterDraft> $result */
        $result = array_map(
            static fn (int $index): ClusterDraft => $drafts[$index],
            $winners,
        );

        $postCount = count($result);

        $this->emitMonitoring('selection_completed', [
            'pre_count'          => $total,
            'post_count'         => $postCount,
            'dropped_duplicates' => max(0, $total - $postCount),
            'dropped_count'      => max(0, $total - $postCount),
            'unique_fingerprints'=> $postCount,
        ]);

        return $result;
    }

    /**
     * @param list<int> $normA
     * @param list<int> $normB
     */
    private function isBetter(ClusterDraft $a, array $normA, ClusterDraft $b, array $normB): bool
    {
        $qualityA = $this->resolveAverageQuality($a);
        $qualityB = $this->resolveAverageQuality($b);
        if ($qualityA !== null || $qualityB !== null) {
            if ($qualityA === null) {
                return false;
            }

            if ($qualityB === null) {
                return true;
            }

            if ($qualityA !== $qualityB) {
                return $qualityA > $qualityB;
            }
        }

        $facesA = $this->resolveFacesCount($a);
        $facesB = $this->resolveFacesCount($b);
        if ($facesA !== null || $facesB !== null) {
            if ($facesA === null) {
                return false;
            }

            if ($facesB === null) {
                return true;
            }

            if ($facesA !== $facesB) {
                return $facesA > $facesB;
            }
        }

        $scoreA = $this->computeScore($a, $normA);
        $scoreB = $this->computeScore($b, $normB);
        if ($scoreA !== $scoreB) {
            return $scoreA > $scoreB;
        }

        $priorityA = (int) ($this->priorityMap[$a->getAlgorithm()] ?? 0);
        $priorityB = (int) ($this->priorityMap[$b->getAlgorithm()] ?? 0);
        if ($priorityA !== $priorityB) {
            return $priorityA > $priorityB;
        }

        return count($normA) >= count($normB);
    }

    /**
     * @param list<int> $members
     */
    private function buildCompositeFingerprint(ClusterDraft $draft, array $members): string
    {
        $components = ['members' => $members];

        $dayTag = $this->resolveDayTagBucket($draft);
        if ($dayTag !== null) {
            $components['day_tag_bucket'] = $dayTag;
        }

        $staypoint = $this->resolveStaypointSignature($draft);
        if ($staypoint !== null) {
            $components['staypoint'] = $staypoint;
        }

        $phashBucket = $this->resolvePhashBucket($draft);
        if ($phashBucket !== null) {
            $components['phash_bucket'] = $phashBucket;
        }

        $device = $this->resolveDeviceSignature($draft);
        if ($device !== null) {
            $components['device'] = $device;
        }

        if ($components === ['members' => $members]) {
            return $this->fingerprint($members);
        }

        return $this->fingerprintFromComponents($components);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveDayTagBucket(ClusterDraft $draft): ?array
    {
        $params = $draft->getParams();

        $dayBucket = $this->resolveDayBucket($params);
        $tags      = $this->resolveTagBucket($params);

        if ($dayBucket === null && $tags === []) {
            return null;
        }

        return [
            'day'  => $dayBucket,
            'tags' => $tags,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveDayBucket(array $params): ?string
    {
        $bucketKey = $params['bucket_key'] ?? null;
        if (is_string($bucketKey) && $bucketKey !== '') {
            return $bucketKey;
        }

        $timeRange = $params['time_range'] ?? null;
        if (is_array($timeRange) && isset($timeRange['from'])) {
            $from = (int) $timeRange['from'];
            if ($from > 0) {
                return (new DateTimeImmutable('@' . $from))->format('Y-m-d');
            }
        }

        $year  = $params['year'] ?? null;
        $month = $params['month'] ?? null;
        $day   = $params['day'] ?? null;
        if (is_numeric($year) && is_numeric($month) && is_numeric($day)) {
            return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function resolveTagBucket(array $params): array
    {
        $labels = [];

        $sceneTags = $params['scene_tags'] ?? null;
        if (is_array($sceneTags)) {
            foreach ($sceneTags as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $label = $entry['label'] ?? null;
                if (is_string($label) && $label !== '') {
                    $labels[] = strtolower($label);
                }
            }
        }

        $keywords = $params['keywords'] ?? null;
        if (is_array($keywords)) {
            foreach ($keywords as $keyword) {
                if (is_string($keyword) && $keyword !== '') {
                    $labels[] = strtolower($keyword);
                }
            }
        }

        if ($labels === []) {
            return [];
        }

        $labels = array_values(array_unique($labels));
        sort($labels);

        return $labels;
    }

    private function resolveStaypointSignature(ClusterDraft $draft): ?array
    {
        $params = $draft->getParams();
        $stay   = $params['primaryStaypoint'] ?? null;
        if (!is_array($stay)) {
            return null;
        }

        $start = isset($stay['start']) ? (int) $stay['start'] : null;
        $end   = isset($stay['end']) ? (int) $stay['end'] : null;
        if ($start === null || $end === null) {
            return null;
        }

        $signature = [
            'start' => $start,
            'end'   => $end,
        ];

        if (isset($stay['lat'])) {
            $signature['lat'] = (float) $stay['lat'];
        }

        if (isset($stay['lon'])) {
            $signature['lon'] = (float) $stay['lon'];
        }

        return $signature;
    }

    private function resolvePhashBucket(ClusterDraft $draft): ?array
    {
        $params = $draft->getParams();
        $selection = $params['member_selection'] ?? null;
        if (!is_array($selection)) {
            return null;
        }

        $hashSamples = $selection['hash_samples'] ?? null;
        if (!is_array($hashSamples) || $hashSamples === []) {
            return null;
        }

        $values = [];
        foreach ($hashSamples as $hash) {
            if (!is_string($hash) || $hash === '') {
                continue;
            }

            $values[] = strtolower(substr($hash, 0, 16));
        }

        if ($values === []) {
            return null;
        }

        sort($values);

        return $values;
    }

    private function resolveDeviceSignature(ClusterDraft $draft): ?array
    {
        $params = $draft->getParams();

        $make  = $params['device_make'] ?? null;
        $model = $params['device_model'] ?? null;
        $owner = $params['device_owner'] ?? null;
        $label = $params['device_primary_label'] ?? null;

        $parts = [];
        if (is_string($make) && $make !== '') {
            $parts['make'] = $make;
        }

        if (is_string($model) && $model !== '') {
            $parts['model'] = $model;
        }

        if (is_string($owner) && $owner !== '') {
            $parts['owner'] = $owner;
        }

        if (is_string($label) && $label !== '') {
            $parts['label'] = $label;
        }

        return $parts !== [] ? $parts : null;
    }

    private function resolveAverageQuality(ClusterDraft $draft): ?float
    {
        $params = $draft->getParams();
        $quality = $params['quality_avg'] ?? null;
        if (is_numeric($quality)) {
            return (float) $quality;
        }

        $summary = $params['member_quality']['summary'] ?? null;
        if (is_array($summary)) {
            $value = $summary['quality_avg'] ?? null;
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function resolveFacesCount(ClusterDraft $draft): ?float
    {
        $params = $draft->getParams();

        $faces = $params['faces_count'] ?? null;
        if (is_numeric($faces)) {
            return (float) $faces;
        }

        $summary = $params['member_quality']['summary'] ?? null;
        if (is_array($summary)) {
            $value = $summary['faces_count'] ?? null;
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        $coverage = $params['people_face_coverage'] ?? null;
        if (!is_numeric($coverage)) {
            if (is_array($summary)) {
                $coverage = $summary['people_face_coverage'] ?? null;
            }
        }

        if (is_numeric($coverage)) {
            $memberCount = max(0, count($draft->getMembers()));

            return (float) $coverage * $memberCount;
        }

        return null;
    }

    /**
     * @param array<string, int|float|string|list<string>|null> $payload
     */
    private function emitMonitoring(string $event, array $payload): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $this->monitoringEmitter->emit('duplicate_collapse', $event, $payload);
    }
}
