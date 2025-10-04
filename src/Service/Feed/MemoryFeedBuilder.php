<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Feed\MemoryFeedItem;
use MagicSunday\Memories\Repository\MediaRepository;
use MagicSunday\Memories\Service\Clusterer\TitleGeneratorInterface;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function arsort;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function usort;

/**
 * iOS-like feed selection:
 * - filter by min score and min members
 * - sort by score desc
 * - limit per calendar day
 * - simple diversity by (place, algorithm)
 * - pick cover by heuristic
 *
 * ClusterDraft::getParams() is expected to expose a non-empty 'group' key which
 * identifies the consolidated algorithm family (e.g. travel_and_places). The
 * scorer adds this metadata for freshly created drafts, while persisted drafts
 * are backfilled during mapping.
 */
final readonly class MemoryFeedBuilder implements FeedBuilderInterface
{
    public function __construct(
        private TitleGeneratorInterface $titleGen,
        private CoverPickerInterface $coverPicker,
        private MediaRepository $mediaRepo,
        private float $minScore = 0.35,
        private int $minMembers = 4,
        private int $maxPerDay = 6,
        private int $maxTotal = 60,
        private int $maxPerAlgorithm = 12,
    ) {
    }

    public function build(array $clusters): array
    {
        // 1) filter
        $filtered = [];
        foreach ($clusters as $c) {
            $score        = (float) ($c->getParams()['score'] ?? 0.0);
            $membersCount = $c->getMembersCount();
            if ($score < $this->minScore) {
                continue;
            }

            if ($membersCount < $this->minMembers) {
                continue;
            }

            $members = $c->getMembers();
            $filtered[] = $c;
        }

        if ($filtered === []) {
            return [];
        }

        // 2) sort high → low score
        usort($filtered, static function (ClusterDraft $a, ClusterDraft $b): int {
            $sa = (float) ($a->getParams()['score'] ?? 0.0);
            $sb = (float) ($b->getParams()['score'] ?? 0.0);

            return $sa < $sb ? 1 : -1;
        });

        // 3) day caps + simple diversity
        /** @var array<string,int> $dayCount */
        $dayCount = [];
        /** @var array<string,int> $seenPlace */
        $seenPlace = [];
        /** @var array<string,int> $seenAlg */
        $seenAlg = [];
        /** @var array<string,int> $algCount */
        $algCount = [];

        $result = [];

        foreach ($filtered as $c) {
            if (count($result) >= $this->maxTotal) {
                break;
            }

            $dayKey = $this->dayKey($c);
            if ($dayKey === null) {
                continue;
            }

            $cap = (int) ($dayCount[$dayKey] ?? 0);
            if ($cap >= $this->maxPerDay) {
                continue;
            }

            $place = $c->getParams()['place'] ?? null;
            $alg   = $c->getAlgorithm();

            if (!is_string($alg)) {
                continue;
            }

            if (($algCount[$alg] ?? 0) >= $this->maxPerAlgorithm) {
                continue;
            }

            // simple diversity: limit repeats
            if (is_string($place)) {
                $key = sprintf('%s|%s', $dayKey, $place);
                if (($seenPlace[$key] ?? 0) >= 2) { // max 2 per place/day
                    continue;
                }
            }

            $algKey = sprintf('%s|%s', $dayKey, $alg);
            if (($seenAlg[$algKey] ?? 0) >= 2) { // max 2 per algo/day
                continue;
            }

            // 4) resolve Media + pick cover
            $members = $this->mediaRepo->findByIds(
                $c->getMembers(),
                $c->getAlgorithm() === 'video_stories'
            );
            if ($members === []) {
                continue;
            }

            $members = array_values(array_filter(
                $members,
                static fn (Media $media): bool => $media->isNoShow() === false,
            ));

            if ($members === []) {
                continue;
            }

            $coverId = $c->getCoverMediaId();
            $cover   = null;
            if ($coverId !== null) {
                foreach ($members as $member) {
                    if ($member->getId() === $coverId) {
                        $cover = $member;
                        break;
                    }
                }
            }

            if ($cover === null) {
                $cover   = $this->coverPicker->pickCover($members, $c->getParams());
                $coverId = $cover?->getId();
            }

            $members = $this->sortMembersByTakenAt($members, $coverId);
            $memberIds = array_map(static function (Media $media): int {
                return $media->getId();
            }, $members);

            // 5) titles
            $title    = $this->titleGen->makeTitle($c);
            $subtitle = $this->titleGen->makeSubtitle($c);

            $params = $c->getParams();
            if (!isset($params['scene_tags'])) {
                $aggregated = $this->aggregateSceneTags($members, 5);
                if ($aggregated !== []) {
                    $params['scene_tags'] = $aggregated;
                }
            }

            $result[] = new MemoryFeedItem(
                algorithm: $alg,
                title: $title,
                subtitle: $subtitle,
                coverMediaId: $coverId,
                memberIds: $memberIds,
                score: (float) ($c->getParams()['score'] ?? 0.0),
                params: $params
            );

            $dayCount[$dayKey] = $cap + 1;
            if (is_string($place)) {
                $seenPlace[sprintf('%s|%s', $dayKey, $place)] = ($seenPlace[sprintf('%s|%s', $dayKey, $place)] ?? 0) + 1;
            }

            $seenAlg[$algKey] = ($seenAlg[$algKey] ?? 0) + 1;
            $algCount[$alg]   = ($algCount[$alg] ?? 0) + 1;
        }

        return $result;
    }

    private function dayKey(ClusterDraft $c): ?string
    {
        $tr = $c->getParams()['time_range'] ?? null;
        if (!is_array($tr) || !isset($tr['to'])) {
            return null;
        }

        $to = (int) $tr['to'];
        if ($to <= 0) {
            return null;
        }

        $d = (new DateTimeImmutable('@' . $to))->setTimezone(new DateTimeZone('Europe/Berlin'));

        return $d->format('Y-m-d');
    }

    /**
     * @param list<Media> $members
     *
     * @return list<Media>
     */
    private function sortMembersByTakenAt(array $members, ?int $coverId): array
    {
        usort($members, static function (Media $a, Media $b) use ($coverId): int {
            if ($coverId !== null) {
                if ($a->getId() === $coverId && $b->getId() !== $coverId) {
                    return -1;
                }

                if ($b->getId() === $coverId && $a->getId() !== $coverId) {
                    return 1;
                }
            }

            $timestampA = $a->getTakenAt()?->getTimestamp() ?? 0;
            $timestampB = $b->getTakenAt()?->getTimestamp() ?? 0;

            return $timestampA <=> $timestampB;
        });

        return $members;
    }

    /**
     * @param list<Media> $members
     *
     * @return list<array{label: string, score: float}>
     */
    private function aggregateSceneTags(array $members, int $limit): array
    {
        /** @var array<string, float> $scores */
        $scores = [];

        foreach ($members as $media) {
            $tags = $media->getSceneTags();
            if (!is_array($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                $label = $tag['label'] ?? null;
                $score = $tag['score'] ?? null;

                if (!is_string($label)) {
                    continue;
                }

                if (!is_float($score) && !is_int($score)) {
                    continue;
                }

                $value = (float) $score;
                if ($value < 0.0) {
                    $value = 0.0;
                }

                if ($value > 1.0) {
                    $value = 1.0;
                }

                $existing = $scores[$label] ?? 0.0;
                if ($value > $existing) {
                    $scores[$label] = $value;
                }
            }
        }

        if ($scores === []) {
            return [];
        }

        arsort($scores);
        $scores = array_slice($scores, 0, $limit, true);

        $result = [];
        foreach ($scores as $label => $score) {
            $result[] = ['label' => $label, 'score' => $score];
        }

        return $result;
    }
}
