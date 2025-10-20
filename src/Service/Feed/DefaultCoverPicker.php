<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Utility\Phash;

use function abs;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_keys;
use function array_values;
use function count;
use function floor;
use function max;
use function min;
use function sort;
use function strtolower;
use function str_contains;
use function trim;
use function sqrt;
use function array_sum;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;

use const SORT_NUMERIC;

/**
 * Heuristic cover picker:
 * - prefers landscape
 * - higher megapixels
 * - aspect close to 16:9 or 4:3
 * - timestamp near median
 * - has thumbnails
 */
final class DefaultCoverPicker implements CoverPickerInterface
{
    public function pickCover(array $members, array $clusterParams): ?Media
    {
        if ($members === []) {
            return null;
        }

        $context = $this->buildContext($members, $clusterParams);

        $best      = null;
        $bestScore = -1.0;

        foreach ($members as $m) {
            $score = $this->score($m, $context);
            if ($score > $bestScore) {
                $best      = $m;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @param array{
     *     medianTimestamp: int|null,
     *     memberQuality: array{
     *         summary: array{quality_avg: float|null, aesthetics_avg: float|null},
     *         weights: array{quality: float, aesthetics: float},
     *         duplicate: array{phash: float, dhash: float},
     *         members: array<int, array{score?: float, quality?: float, aesthetics?: float, penalty?: float}>
     *     },
     *     people: array{
     *         emphasis: float,
     *         coverage: float|null,
     *         faceCoverage: float|null,
     *         ratio: float|null,
     *         primary: string|null
     *     },
     *     travel: array{
     *         distance: float|null,
     *         waypointCount: int,
     *         eventCount: int,
     *         weight: float
     *     },
     *     fingerprints: array{
     *         phashCounts: array<string,int>,
     *         dhashCounts: array<string,int>,
     *         phashDistinct: list<string>,
     *         dhashDistinct: list<string>,
     *         centroid: array{phash: string|null, dhash: string|null}
     *     },
     *     cluster: array<string,mixed>
     * } $context
     */
    private function score(Media $m, array $context): float
    {
        $width  = $m->getWidth() ?? 0;
        $height = $m->getHeight() ?? 0;

        [$w, $h] = $this->normalizeDimensions($width, $height, $m);

        $areaMp = $w > 0 && $h > 0 ? (($w * $h) / 1_000_000.0) : 0.0;

        $landscape = ($w >= $h) ? 1.0 : 0.0;

        $ratio       = ($h > 0) ? ($w / (float) $h) : 0.0;
        $d169        = abs($ratio - (16.0 / 9.0));
        $d43         = abs($ratio - (4.0 / 3.0));
        $aspectScore = 1.0 - min(1.0, min($d169, $d43));

        $timeScore  = $this->timeScore($m, $context['medianTimestamp']);
        $thumbBonus = ($m->getThumbnails() !== null && $m->getThumbnails() !== []) ? 1.0 : 0.0;
        $sizeScore  = min(1.0, (float) $m->getSize() / 8_000_000.0);

        $composition = $this->clamp01(
            0.30 * min(1.0, $areaMp / 12.0) +
            0.18 * $landscape +
            0.12 * $aspectScore +
            0.30 * $timeScore +
            0.08 * $sizeScore +
            0.02 * $thumbBonus,
        );

        $quality     = $this->qualityScore($m, $context['memberQuality'], $areaMp);
        $aesthetic   = $this->aestheticScore($m, $context['memberQuality']);
        $peopleScore = $this->peopleScore($m, $context['people']);
        $saliency    = $this->saliencyScore($m);
        $travel      = $this->travelScore($m, $context['travel'], $landscape, $areaMp);
        $pose        = $this->poseScore($m, $context['people']);

        $base =
            0.20 * $composition +
            0.24 * $quality +
            0.16 * $aesthetic +
            0.14 * $peopleScore +
            0.10 * $saliency +
            0.10 * $travel +
            0.06 * $pose;

        $duplicatePenalty = $this->duplicatePenalty($m, $context['fingerprints'], $context['memberQuality']);

        $score = $this->clamp01($base);

        if ($duplicatePenalty > 0.0) {
            $score *= max(0.0, 1.0 - min(0.9, $duplicatePenalty));
        }

        return $score;
    }

    /**
     * @return array{int,int}
     */
    private function normalizeDimensions(int $width, int $height, Media $media): array
    {
        if ($width === 0 || $height === 0) {
            return [$width, $height];
        }

        if (!$media->needsRotation()) {
            return [$width, $height];
        }

        $orientation = $media->getOrientation();

        return match ($orientation) {
            5, 6, 7, 8 => [$height, $width],
            default => [$width, $height],
        };
    }

    /**
     * @param list<Media> $members
     * @param array<string,mixed> $clusterParams
     *
     * @return array{
     *     medianTimestamp: int|null,
     *     memberQuality: array{
     *         summary: array{quality_avg: float|null, aesthetics_avg: float|null},
     *         weights: array{quality: float, aesthetics: float},
     *         duplicate: array{phash: float, dhash: float},
     *         members: array<int, array{score?: float, quality?: float, aesthetics?: float, penalty?: float}>
     *     },
     *     people: array{
     *         emphasis: float,
     *         coverage: float|null,
     *         faceCoverage: float|null,
     *         ratio: float|null,
     *         primary: string|null
     *     },
     *     travel: array{
     *         distance: float|null,
     *         waypointCount: int,
     *         eventCount: int,
     *         weight: float
     *     },
     *     fingerprints: array{
     *         phashCounts: array<string,int>,
     *         dhashCounts: array<string,int>,
     *         phashDistinct: list<string>,
     *         dhashDistinct: list<string>,
     *         centroid: array{phash: string|null, dhash: string|null}
     *     },
     *     cluster: array<string,mixed>
     * }
     */
    private function buildContext(array $members, array $clusterParams): array
    {
        /** @var list<int|null> $timestamps */
        $timestamps = array_map(
            static fn (Media $media): ?int => $media->getTakenAt()?->getTimestamp(),
            $members,
        );

        /** @var list<int> $ts */
        $ts = array_values(array_filter(
            $timestamps,
            static fn (?int $timestamp): bool => $timestamp !== null,
        ));

        sort($ts, SORT_NUMERIC);
        $medianTs = $ts !== [] ? $ts[(int) floor(count($ts) / 2)] : null;

        return [
            'medianTimestamp' => $medianTs,
            'memberQuality'   => $this->normaliseMemberQuality($clusterParams['member_quality'] ?? []),
            'people'          => $this->normalisePeopleParams($clusterParams),
            'travel'          => $this->normaliseTravelParams($clusterParams),
            'fingerprints'    => $this->collectFingerprintMeta($members),
            'cluster'         => $clusterParams,
        ];
    }

    /**
     * @param array<string,mixed> $memberQuality
     *
     * @return array{
     *     summary: array{quality_avg: float|null, aesthetics_avg: float|null},
     *     weights: array{quality: float, aesthetics: float},
     *     duplicate: array{phash: float, dhash: float},
     *     members: array<int, array{score?: float, quality?: float, aesthetics?: float, penalty?: float}>
     * }
     */
    private function normaliseMemberQuality(array $memberQuality): array
    {
        $summary    = is_array($memberQuality['summary'] ?? null) ? $memberQuality['summary'] : [];
        $weightsRaw = is_array($memberQuality['weights'] ?? null) ? $memberQuality['weights'] : [];

        $members = [];
        if (is_array($memberQuality['members'] ?? null)) {
            foreach ($memberQuality['members'] as $id => $data) {
                if (!is_array($data)) {
                    continue;
                }

                $intId = is_numeric($id) ? (int) $id : null;
                if ($intId === null) {
                    continue;
                }

                $members[$intId] = [
                    'score'      => isset($data['score']) ? $this->clamp01((float) $data['score']) : null,
                    'quality'    => isset($data['quality']) ? $this->clamp01((float) $data['quality']) : null,
                    'aesthetics' => isset($data['aesthetics']) ? $this->clamp01((float) $data['aesthetics']) : null,
                    'penalty'    => isset($data['penalty']) ? $this->clamp01((float) $data['penalty']) : null,
                ];
            }
        }

        return [
            'summary'   => [
                'quality_avg'    => isset($summary['quality_avg']) ? $this->clamp01((float) $summary['quality_avg']) : null,
                'aesthetics_avg' => isset($summary['aesthetics_avg']) ? $this->clamp01((float) $summary['aesthetics_avg']) : null,
            ],
            'weights'   => [
                'quality'    => isset($weightsRaw['quality']) ? max(0.0, (float) $weightsRaw['quality']) : 0.7,
                'aesthetics' => isset($weightsRaw['aesthetics']) ? max(0.0, (float) $weightsRaw['aesthetics']) : 0.3,
            ],
            'duplicate' => [
                'phash' => isset($weightsRaw['duplicates']['phash']) ? max(0.0, (float) $weightsRaw['duplicates']['phash']) : 0.35,
                'dhash' => isset($weightsRaw['duplicates']['dhash']) ? max(0.0, (float) $weightsRaw['duplicates']['dhash']) : 0.25,
            ],
            'members'   => $members,
        ];
    }

    /**
     * @param array<string,mixed> $clusterParams
     *
     * @return array{
     *     emphasis: float,
     *     coverage: float|null,
     *     faceCoverage: float|null,
     *     ratio: float|null,
     *     primary: string|null
     * }
     */
    private function normalisePeopleParams(array $clusterParams): array
    {
        $coverage     = $this->floatOrNull($clusterParams['people_coverage'] ?? null);
        $faceCoverage = $this->floatOrNull($clusterParams['people_face_coverage'] ?? null);
        $ratio        = $this->floatOrNull($clusterParams['people_ratio'] ?? null);

        $people = $clusterParams['people'] ?? null;
        if (is_array($people)) {
            $coverage     ??= $this->floatOrNull($people['people_coverage'] ?? $people['coverage'] ?? null);
            $faceCoverage ??= $this->floatOrNull($people['people_face_coverage'] ?? $people['faceCoverage'] ?? null);
        }

        $primary = null;
        if (is_string($clusterParams['people_primary_subject'] ?? null)) {
            $primary = (string) $clusterParams['people_primary_subject'];
        }

        $emphasis = max(
            $coverage ?? 0.0,
            $faceCoverage ?? 0.0,
            $ratio ?? 0.0,
        );

        return [
            'emphasis'     => $this->clamp01($emphasis),
            'coverage'     => $coverage,
            'faceCoverage' => $faceCoverage,
            'ratio'        => $ratio,
            'primary'      => $primary,
        ];
    }

    /**
     * @param array<string,mixed> $clusterParams
     *
     * @return array{
     *     distance: float|null,
     *     waypointCount: int,
     *     eventCount: int,
     *     weight: float
     * }
     */
    private function normaliseTravelParams(array $clusterParams): array
    {
        $distance = $this->floatOrNull($clusterParams['total_travel_km'] ?? null);
        if ($distance === null) {
            $summary = $clusterParams['travel_summary'] ?? null;
            if (is_array($summary)) {
                $distance = $this->floatOrNull($summary['total_travel_km'] ?? $summary['travel_km'] ?? null);
            }
        }

        $segments = $clusterParams['travel_segments'] ?? null;
        $waypoints = $clusterParams['travel_waypoints'] ?? null;
        $events    = $clusterParams['travel_events'] ?? null;

        $waypointCount = is_array($waypoints) ? count($waypoints) : 0;
        $eventCount    = is_array($events) ? count($events) : 0;
        $segmentCount  = is_array($segments) ? count($segments) : 0;

        $weightBase = 0.0;
        if ($distance !== null) {
            $weightBase = min(1.0, max(0.0, $distance / 150.0));
        }

        $weight = min(1.0, $weightBase + 0.15 * (float) ($waypointCount > 2 ? 1 : 0) + 0.1 * (float) ($segmentCount > 1 ? 1 : 0));

        return [
            'distance'     => $distance,
            'waypointCount'=> $waypointCount,
            'eventCount'   => $eventCount,
            'weight'       => $weight,
        ];
    }

    /**
     * @param list<Media> $members
     *
     * @return array{
     *     phashCounts: array<string,int>,
     *     dhashCounts: array<string,int>,
     *     phashDistinct: list<string>,
     *     dhashDistinct: list<string>,
     *     centroid: array{phash: string|null, dhash: string|null}
     * }
     */
    private function collectFingerprintMeta(array $members): array
    {
        $phashCounts = [];
        $dhashCounts = [];

        foreach ($members as $media) {
            $phash = $this->fingerprintOrNull($media->getPhash64()) ?? $this->fingerprintOrNull($media->getPhash());
            if ($phash !== null) {
                $phashCounts[$phash] = ($phashCounts[$phash] ?? 0) + 1;
            }

            $dhash = $this->fingerprintOrNull($media->getDhash());
            if ($dhash !== null) {
                $dhashCounts[$dhash] = ($dhashCounts[$dhash] ?? 0) + 1;
            }
        }

        return [
            'phashCounts'   => $phashCounts,
            'dhashCounts'   => $dhashCounts,
            'phashDistinct' => array_values(array_keys($phashCounts)),
            'dhashDistinct' => array_values(array_keys($dhashCounts)),
            'centroid'      => [
                'phash' => $this->fingerprintCentroid($phashCounts),
                'dhash' => $this->fingerprintCentroid($dhashCounts),
            ],
        ];
    }

    /**
     * @param array<string,int> $counts
     */
    private function fingerprintCentroid(array $counts): ?string
    {
        if ($counts === []) {
            return null;
        }

        $max      = -1;
        $centroid = null;

        foreach ($counts as $hash => $count) {
            if ($count > $max) {
                $max      = $count;
                $centroid = $hash;
            }
        }

        return $centroid;
    }

    private function timeScore(Media $media, ?int $medianTs): float
    {
        $timestamp = $media->getTakenAt()?->getTimestamp();
        if ($medianTs === null || $timestamp === null) {
            return 0.5;
        }

        $deltaHours = abs($timestamp - $medianTs) / 3600.0;

        return max(0.0, 1.0 - min(1.0, $deltaHours / 12.0));
    }

    /**
     * @param array{
     *     summary: array{quality_avg: float|null, aesthetics_avg: float|null},
     *     weights: array{quality: float, aesthetics: float},
     *     duplicate: array{phash: float, dhash: float},
     *     members: array<int, array{score?: float, quality?: float, aesthetics?: float, penalty?: float}>
     * } $meta
     */
    private function qualityScore(Media $media, array $meta, float $areaMp): float
    {
        $detail = $meta['members'][$media->getId()] ?? null;

        $score = $media->getQualityScore();
        if ($detail !== null && isset($detail['quality'])) {
            $score = max($score ?? 0.0, (float) $detail['quality']);
        } elseif ($detail !== null && isset($detail['score'])) {
            $score = max($score ?? 0.0, (float) $detail['score']);
        }

        if ($score === null) {
            $score = min(1.0, $areaMp / 12.0);
        }

        if ($media->isLowQuality()) {
            $score *= 0.6;
        }

        $qualityAvg = $meta['summary']['quality_avg'];
        if ($qualityAvg !== null && $qualityAvg > 0.0) {
            $relative = $score / max(0.0001, $qualityAvg);
            $score    = ($score * 0.7) + ($this->clamp01($relative) * 0.3);
        }

        return $this->clamp01($score);
    }

    /**
     * @param array{
     *     summary: array{quality_avg: float|null, aesthetics_avg: float|null},
     *     weights: array{quality: float, aesthetics: float},
     *     duplicate: array{phash: float, dhash: float},
     *     members: array<int, array{score?: float, quality?: float, aesthetics?: float, penalty?: float}>
     * } $meta
     */
    private function aestheticScore(Media $media, array $meta): float
    {
        $detail = $meta['members'][$media->getId()] ?? null;
        $score  = null;

        if ($detail !== null && isset($detail['aesthetics'])) {
            $score = (float) $detail['aesthetics'];
        }

        if ($score === null) {
            $components = [];

            $exposure = $media->getQualityExposure();
            if ($exposure !== null) {
                $components[] = $this->clamp01($exposure);
            } else {
                $brightness = $media->getBrightness();
                if ($brightness !== null) {
                    $components[] = $this->balancedScore($this->clamp01($brightness), 0.55, 0.35);
                }

                $contrast = $media->getContrast();
                if ($contrast !== null) {
                    $components[] = $this->clamp01($contrast);
                }
            }

            $entropy = $media->getEntropy();
            if ($entropy !== null) {
                $components[] = $this->clamp01($entropy);
            }

            $color = $media->getColorfulness();
            if ($color !== null) {
                $components[] = $this->clamp01($color);
            }

            if ($components !== []) {
                $score = array_sum($components) / count($components);
            } else {
                $score = 0.5;
            }
        }

        $aestheticAvg = $meta['summary']['aesthetics_avg'];
        if ($aestheticAvg !== null && $aestheticAvg > 0.0) {
            $relative = $score / max(0.0001, $aestheticAvg);
            $score    = ($score * 0.65) + ($this->clamp01($relative) * 0.35);
        }

        return $this->clamp01($score);
    }

    /**
     * @param array{
     *     emphasis: float,
     *     coverage: float|null,
     *     faceCoverage: float|null,
     *     ratio: float|null,
     *     primary: string|null
     * } $people
     */
    private function peopleScore(Media $media, array $people): float
    {
        $hasFaces  = $media->hasFaces();
        $faces     = $media->getFacesCount();
        $coverage  = $this->extractFaceCoverage($media);
        $faceScore = 0.0;

        if ($hasFaces) {
            $faceScore += 0.4;
        }

        if ($faces > 0) {
            $faceScore += min(0.2, $faces / 5.0);
        }

        if ($coverage !== null) {
            $faceScore += min(0.35, $coverage * 0.5 + max(0.0, $coverage - 0.3));
        }

        $faceScore = $this->clamp01($faceScore);

        $emphasis = $people['emphasis'];
        if ($emphasis > 0.5) {
            $faceScore = $this->clamp01($faceScore * (1.0 + 0.3 * $emphasis));
        } else {
            $faceScore = $this->clamp01(($faceScore * 0.8) + (0.2 * $emphasis));
        }

        $primary = $people['primary'] ?? null;
        if (is_string($primary)) {
            $primary = strtolower(trim($primary));

            if ($primary !== '') {
                $persons = $media->getPersons();

                if (is_array($persons)) {
                    foreach ($persons as $person) {
                        if (!is_string($person)) {
                            continue;
                        }

                        $personId = strtolower(trim($person));
                        if ($personId === '') {
                            continue;
                        }

                        if ($personId === $primary) {
                            $faceScore = $this->clamp01($faceScore + 0.15);
                            break;
                        }
                    }
                }
            }
        }

        return $faceScore;
    }

    private function saliencyScore(Media $media): float
    {
        $bag       = $media->getFeatureBag();
        $saliency  = $bag->namespaceValues('saliency');
        $ruleScore = $this->floatOrNull($saliency['rule_of_thirds_score'] ?? $saliency['ruleOfThirdsScore'] ?? null);
        $confidence = $this->floatOrNull($saliency['confidence'] ?? null);

        $center = null;
        if (array_key_exists('center', $saliency)) {
            $center = $this->pointFromValue($saliency['center']);
        } elseif (array_key_exists('center_of_mass', $saliency)) {
            $center = $this->pointFromValue($saliency['center_of_mass']);
        } elseif (array_key_exists('centroid', $saliency)) {
            $center = $this->pointFromValue($saliency['centroid']);
        }

        $alignment = 0.5;
        if ($center !== null) {
            $alignment = $this->ruleOfThirdsAlignment($center[0], $center[1]);
        }

        $score = 0.55 * $alignment;

        if ($ruleScore !== null) {
            $score += 0.30 * $this->clamp01($ruleScore);
        } else {
            $score += 0.15;
        }

        if ($confidence !== null) {
            $score += 0.15 * $this->clamp01($confidence);
        } else {
            $score += 0.05;
        }

        return $this->clamp01($score);
    }

    /**
     * @param array{
     *     distance: float|null,
     *     waypointCount: int,
     *     eventCount: int,
     *     weight: float
     * } $travel
     */
    private function travelScore(Media $media, array $travel, float $landscape, float $areaMp): float
    {
        $base = 0.4 + (0.2 * $landscape);

        if ($media->isPanorama() === true) {
            $base += 0.1;
        }

        if ($media->getGpsLat() !== null && $media->getGpsLon() !== null) {
            $base += 0.15;
        }

        if ($media->getLocation() !== null) {
            $base += 0.1;
        }

        $base += min(0.15, $areaMp / 20.0);

        $base = $this->clamp01($base);

        $weight = $travel['weight'];
        if ($weight <= 0.0) {
            return $this->clamp01(($base * 0.5) + 0.25);
        }

        return $this->clamp01(($base * (0.6 + 0.4 * $weight)) + (0.15 * $weight));
    }

    /**
     * @param array{
     *     emphasis: float,
     *     coverage: float|null,
     *     faceCoverage: float|null,
     *     ratio: float|null,
     *     primary: string|null
     * } $people
     */
    private function poseScore(Media $media, array $people): float
    {
        $bag      = $media->getFeatureBag();
        $vision   = $bag->namespaceValues('vision');
        $peopleNs = $bag->namespaceValues('people');

        $poseRaw = $vision['primary_pose'] ?? $peopleNs['primary_pose'] ?? $peopleNs['pose'] ?? null;
        $pose    = is_string($poseRaw) ? strtolower($poseRaw) : null;
        $confidence = $this->floatOrNull($vision['primary_pose_confidence'] ?? $peopleNs['pose_confidence'] ?? null);

        $score = 0.5;
        if ($pose !== null) {
            $score = match (true) {
                str_contains($pose, 'smil') => 0.95,
                str_contains($pose, 'front') => 0.85,
                str_contains($pose, 'group') => 0.8,
                str_contains($pose, 'profile') => 0.55,
                str_contains($pose, 'back') => 0.35,
                default => 0.5,
            };
        }

        if ($confidence !== null) {
            $score = ($score * 0.6) + ($this->clamp01($confidence) * 0.4);
        }

        $emphasis = $people['emphasis'];
        if ($emphasis > 0.5) {
            $score = $this->clamp01($score * (0.8 + 0.4 * $emphasis));
        }

        return $this->clamp01($score);
    }

    /**
     * @param array{
     *     phashCounts: array<string,int>,
     *     dhashCounts: array<string,int>,
     *     phashDistinct: list<string>,
     *     dhashDistinct: list<string>,
     *     centroid: array{phash: string|null, dhash: string|null}
     * } $fingerprints
     * @param array{
     *     summary: array{quality_avg: float|null, aesthetics_avg: float|null},
     *     weights: array{quality: float, aesthetics: float},
     *     duplicate: array{phash: float, dhash: float},
     *     members: array<int, array{score?: float, quality?: float, aesthetics?: float, penalty?: float}>
     * } $meta
     */
    private function duplicatePenalty(Media $media, array $fingerprints, array $meta): float
    {
        $penalty = 0.0;

        $detail = $meta['members'][$media->getId()] ?? null;
        if ($detail !== null && isset($detail['penalty'])) {
            $penalty = max($penalty, (float) $detail['penalty']);
        }

        $phash = $this->fingerprintOrNull($media->getPhash64()) ?? $this->fingerprintOrNull($media->getPhash());
        if ($phash !== null) {
            $phashPenalty = $this->duplicateDistanceScore(
                $phash,
                $fingerprints['phashCounts'],
                $fingerprints['phashDistinct'],
                $fingerprints['centroid']['phash'],
            );

            $penalty = max($penalty, $phashPenalty * max(0.4, $meta['duplicate']['phash']));
        }

        $dhash = $this->fingerprintOrNull($media->getDhash());
        if ($dhash !== null) {
            $dhashPenalty = $this->duplicateDistanceScore(
                $dhash,
                $fingerprints['dhashCounts'],
                $fingerprints['dhashDistinct'],
                $fingerprints['centroid']['dhash'],
            );

            $penalty = max($penalty, $dhashPenalty * max(0.3, $meta['duplicate']['dhash']));
        }

        return min(0.9, $penalty);
    }

    /**
     * @param array<string,int> $counts
     * @param list<string> $distinct
     */
    private function duplicateDistanceScore(string $value, array $counts, array $distinct, ?string $centroid): float
    {
        $value = strtolower($value);
        $count = $counts[$value] ?? 0;

        $penalty = 0.0;
        if ($count > 1) {
            $penalty = min(1.0, 0.65 + 0.1 * ($count - 2));
        }

        $minDistance = null;
        foreach ($distinct as $other) {
            if ($other === $value) {
                continue;
            }

            $distance = Phash::hammingFromHex($value, $other);
            if ($minDistance === null || $distance < $minDistance) {
                $minDistance = $distance;
            }
        }

        if ($minDistance !== null) {
            $penalty = max($penalty, $this->penaltyFromDistance($minDistance));
        }

        if ($centroid !== null) {
            $distance = Phash::hammingFromHex($value, $centroid);
            $penalty  = max($penalty, 0.9 * $this->penaltyFromDistance($distance));
        }

        return $penalty;
    }

    private function penaltyFromDistance(int $distance): float
    {
        if ($distance <= 0) {
            return 1.0;
        }

        if ($distance >= 16) {
            return 0.0;
        }

        return max(0.0, 1.0 - ($distance / 16.0));
    }

    private function extractFaceCoverage(Media $media): ?float
    {
        $bag       = $media->getFeatureBag();
        $vision    = $bag->namespaceValues('vision');
        $faces     = $bag->namespaceValues('faces');
        $people    = $bag->namespaceValues('people');

        $coverage = $vision['face_coverage'] ?? $vision['faceCoverage']
            ?? $faces['coverage'] ?? $faces['face_coverage'] ?? $people['face_coverage'] ?? $people['faceCoverage'] ?? null;

        return $this->floatOrNull($coverage);
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function pointFromValue(mixed $value): ?array
    {
        if (is_array($value)) {
            if (array_key_exists('x', $value) && array_key_exists('y', $value)) {
                return [
                    $this->clamp01((float) $value['x']),
                    $this->clamp01((float) $value['y']),
                ];
            }

            if (isset($value[0], $value[1]) && is_numeric($value[0]) && is_numeric($value[1])) {
                return [
                    $this->clamp01((float) $value[0]),
                    $this->clamp01((float) $value[1]),
                ];
            }
        }

        return null;
    }

    private function ruleOfThirdsAlignment(float $x, float $y): float
    {
        $targets = [1.0 / 3.0, 2.0 / 3.0];

        $dx = min(...array_map(static fn (float $t): float => abs($x - $t), $targets));
        $dy = min(...array_map(static fn (float $t): float => abs($y - $t), $targets));

        $distance = sqrt(($dx ** 2) + ($dy ** 2));

        return max(0.0, 1.0 - min(1.0, $distance / 0.5));
    }

    private function fingerprintOrNull(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }

    private function balancedScore(float $value, float $midpoint, float $tolerance): float
    {
        $deviation = abs($value - $midpoint);
        $range     = max(0.0001, $tolerance);

        return max(0.0, 1.0 - min(1.0, $deviation / $range));
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '') {
            return (float) $value;
        }

        return null;
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
