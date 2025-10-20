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
use MagicSunday\Memories\Service\Feed\FeedVisibilityFilter;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function arsort;
use function count;
use function in_array;
use function floor;
use function is_array;
use function is_float;
use function is_int;
use function is_iterable;
use function is_numeric;
use function is_string;
use function mb_strtolower;
use function sprintf;
use function usort;
use function trim;

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
    private FeedPersonalizationProfile $defaultProfile;

    public function __construct(
        private TitleGeneratorInterface $titleGen,
        private CoverPickerInterface $coverPicker,
        private MediaRepository $mediaRepo,
        private SeriesHighlightService $seriesHighlightService,
        private float $minScore = 0.35,
        private int $minMembers = 4,
        private int $maxPerDay = 6,
        private int $maxTotal = 60,
        private int $maxPerAlgorithm = 12,
        private float $qualityFloor = 0.30,
        private float $peopleCoverageThreshold = 0.25,
        private int $recentDays = 30,
        private int $staleDays = 365,
        private float $recentScoreBonus = 0.03,
        private float $staleScorePenalty = 0.05,
        private float $favouritePersonMultiplier = 1.0,
        private float $favouritePlaceMultiplier = 1.0,
        private float $negativePersonMultiplier = 1.0,
        private float $negativePlaceMultiplier = 1.0,
        private float $negativeDateMultiplier = 1.0,
    ) {
        $this->defaultProfile = new FeedPersonalizationProfile(
            'default',
            $this->minScore,
            $this->minMembers,
            $this->maxPerDay,
            $this->maxTotal,
            $this->maxPerAlgorithm,
            $this->qualityFloor,
            $this->peopleCoverageThreshold,
            $this->recentDays,
            $this->staleDays,
            $this->recentScoreBonus,
            $this->staleScorePenalty,
        );
    }

    /**
     * @param list<ClusterDraft> $clusters
     *
     * @return list<MemoryFeedItem>
     */
    public function build(
        array $clusters,
        ?FeedPersonalizationProfile $profile = null,
        ?FeedVisibilityFilter $visibilityFilter = null,
        ?FeedUserPreferences $preferences = null,
    ): array {
        $profile ??= $this->defaultProfile;

        if ($visibilityFilter !== null && !$visibilityFilter->isEmpty()) {
            $clusters = array_values(array_filter(
                $clusters,
                fn (ClusterDraft $cluster): bool => !$this->isClusterHidden($cluster, $visibilityFilter),
            ));

            if ($clusters === []) {
                return [];
            }
        }

        $now = new DateTimeImmutable();

        // 1) filter
        $filtered = array_values(array_filter(
            $clusters,
            function (ClusterDraft $c) use ($profile, $now): bool {
                $params         = $c->getParams();
                $score          = (float) ($params['score'] ?? 0.0);
                $ageInDays      = $this->calculateAgeInDays($c, $now);
                $adjustedScore  = $profile->adjustScoreForAge($score, $ageInDays);
                $qualityAverage = $this->floatParam($params, 'quality_avg');
                $peopleMentions = (int) ($params['people_count'] ?? 0);
                $peopleCoverage = $this->floatParam($params, 'people_coverage') ?? 0.0;

                if ($adjustedScore < $profile->getMinScore()) {
                    return false;
                }

                if ($c->getMembersCount() < $profile->getMinMembers()) {
                    return false;
                }

                if ($qualityAverage !== null && $qualityAverage < $profile->getQualityFloor()) {
                    return false;
                }

                if ($peopleMentions > 0 && $peopleCoverage < $profile->getPeopleCoverageThreshold()) {
                    return false;
                }

                return true;
            }
        ));

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

        /** @var list<MemoryFeedItem> $result */
        $result = [];

        $maxTotal        = $profile->getMaxTotal();
        $maxPerDay       = $profile->getMaxPerDay();
        $maxPerAlgorithm = $profile->getMaxPerAlgorithm();

        foreach ($filtered as $c) {
            $this->seriesHighlightService->apply($c);

            if (count($result) >= $maxTotal) {
                break;
            }

            $dayKey = $this->dayKey($c);
            if ($dayKey === null) {
                continue;
            }

            $cap = (int) ($dayCount[$dayKey] ?? 0);
            if ($cap >= $maxPerDay) {
                continue;
            }

            $place = $c->getParams()['place'] ?? null;
            $alg   = $c->getAlgorithm();

            if (!is_string($alg)) {
                continue;
            }

            if (($algCount[$alg] ?? 0) >= $maxPerAlgorithm) {
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

            $params = $c->getParams();

            $overlay     = $this->resolveCuratedOverlay($params, $profile);
            $usedCurated = false;

            if ($overlay !== null) {
                $curated = $this->applyCuratedOverlay($members, $overlay);
                if ($curated !== null) {
                    $members     = $curated;
                    $usedCurated = true;
                }
            }

            if ($usedCurated === false) {
                $members = $this->sortMembersByTakenAt($members, $coverId);
            }

            $memberIds = array_map(static function (Media $media): int {
                return $media->getId();
            }, $members);

            if ($usedCurated && $coverId !== null && !in_array($coverId, $memberIds, true)) {
                $cover   = $members[0] ?? null;
                $coverId = $cover?->getId();
            }

            if ($overlay !== null) {
                $params = $this->markFeedOverlayUsage($params, $overlay, $usedCurated, count($memberIds));
            }

            // 5) titles
            $title    = $this->titleGen->makeTitle($c);
            $subtitle = $this->titleGen->makeSubtitle($c);

            if (!isset($params['scene_tags'])) {
                $aggregated = $this->aggregateSceneTags($members, 5);
                if ($aggregated !== []) {
                    $params['scene_tags'] = $aggregated;
                }
            }

            $params['personalisierungsProfil'] = $profile->getKey();

            $preferenceResult = $this->applyPreferenceAdjustments($params, $preferences, $members);
            $params          = $preferenceResult['params'];
            $adjustedScore   = $preferenceResult['score'];

            $result[] = new MemoryFeedItem(
                algorithm: $alg,
                title: $title,
                subtitle: $subtitle,
                coverMediaId: $coverId,
                memberIds: $memberIds,
                score: $adjustedScore,
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

    /**
     * @param array<string, mixed> $params
     * @param list<Media>          $members
     * @return array{score: float, params: array<string, scalar|array|null>}
     */
    private function applyPreferenceAdjustments(
        array $params,
        ?FeedUserPreferences $preferences,
        array $members,
    ): array {
        $baseScore = (float) ($params['score'] ?? 0.0);

        if ($preferences === null) {
            return ['score' => $baseScore, 'params' => $params];
        }

        $multiplier = 1.0;
        $favouritePersonMatches = [];
        $favouritePlaceMatches  = [];
        $negativePersonMatches  = [];
        $negativePlaceMatches   = [];
        $negativeDateMatches    = [];

        $clusterPersons = $this->normaliseIdList($params['persons'] ?? null);
        $clusterPersonLookup = $this->normaliseMatchIndex($clusterPersons);
        $preferenceFavouritePersons = $this->normaliseMatchIndex($preferences->getFavouritePersons());

        foreach ($clusterPersons as $index => $person) {
            $normalized = $clusterPersonLookup[$index] ?? null;
            if ($normalized === null) {
                continue;
            }

            if (in_array($normalized, $preferenceFavouritePersons, true)) {
                $favouritePersonMatches[] = $person;
            }
        }

        if ($favouritePersonMatches !== [] && $this->favouritePersonMultiplier !== 1.0) {
            $multiplier *= $this->favouritePersonMultiplier;
        }

        $preferenceHiddenPersons = $this->normaliseMatchIndex($preferences->getHiddenPersons());
        foreach ($clusterPersons as $index => $person) {
            $normalized = $clusterPersonLookup[$index] ?? null;
            if ($normalized === null) {
                continue;
            }

            if (in_array($normalized, $preferenceHiddenPersons, true)) {
                $negativePersonMatches[] = $person;
            }
        }

        if ($negativePersonMatches !== [] && $this->negativePersonMultiplier !== 1.0) {
            $multiplier *= $this->negativePersonMultiplier;
        }

        $placeCandidates = [];
        $primaryPlace    = $this->normaliseScalarString($params['place'] ?? null);
        if ($primaryPlace !== null) {
            $placeCandidates[] = $primaryPlace;
        }

        $placeCandidates = array_values(array_unique(array_merge(
            $placeCandidates,
            $this->normaliseIdList($params['places'] ?? null),
        )));
        $placeLookup = $this->normaliseMatchIndex($placeCandidates);

        $preferenceFavouritePlaces = $this->normaliseMatchIndex($preferences->getFavouritePlaces());
        foreach ($placeCandidates as $index => $place) {
            $normalized = $placeLookup[$index] ?? null;
            if ($normalized === null) {
                continue;
            }

            if (in_array($normalized, $preferenceFavouritePlaces, true)) {
                $favouritePlaceMatches[] = $place;
            }
        }

        if ($favouritePlaceMatches !== [] && $this->favouritePlaceMultiplier !== 1.0) {
            $multiplier *= $this->favouritePlaceMultiplier;
        }

        $preferenceHiddenPlaces = $this->normaliseMatchIndex($preferences->getHiddenPlaces());
        foreach ($placeCandidates as $index => $place) {
            $normalized = $placeLookup[$index] ?? null;
            if ($normalized === null) {
                continue;
            }

            if (in_array($normalized, $preferenceHiddenPlaces, true)) {
                $negativePlaceMatches[] = $place;
            }
        }

        if ($negativePlaceMatches !== [] && $this->negativePlaceMultiplier !== 1.0) {
            $multiplier *= $this->negativePlaceMultiplier;
        }

        $clusterDates = $this->extractTimeRangeDates($params['time_range'] ?? null);
        $dateLookup   = $this->normaliseMatchIndex($clusterDates);
        $hiddenDates  = $this->normaliseMatchIndex($preferences->getHiddenDates());
        foreach ($clusterDates as $index => $date) {
            $normalized = $dateLookup[$index] ?? null;
            if ($normalized === null) {
                continue;
            }

            if (in_array($normalized, $hiddenDates, true)) {
                $negativeDateMatches[] = $date;
            }
        }

        if ($negativeDateMatches !== [] && $this->negativeDateMultiplier !== 1.0) {
            $multiplier *= $this->negativeDateMultiplier;
        }

        if ($multiplier < 0.0) {
            $multiplier = 0.0;
        }

        $adjustedScore = $baseScore * $multiplier;
        if ($adjustedScore < 0.0) {
            $adjustedScore = 0.0;
        }

        $params['score_preference'] = [
            'base' => $baseScore,
            'multiplier' => $multiplier,
            'favourite_persons' => $favouritePersonMatches,
            'favourite_places' => $favouritePlaceMatches,
            'hidden_persons' => $negativePersonMatches,
            'hidden_places' => $negativePlaceMatches,
            'hidden_dates' => $negativeDateMatches,
            'hidden_algorithms' => [],
        ];
        $params['score'] = $adjustedScore;

        if (!isset($params['people_favourite_coverage']) && $favouritePersonMatches !== []) {
            $params['people_favourite_coverage'] = $this->estimateFavouriteCoverage($members, $favouritePersonMatches);
        }

        return ['score' => $adjustedScore, 'params' => $params];
    }

    /**
     * @param list<Media>       $members
     * @param list<string>      $favourites
     */
    private function estimateFavouriteCoverage(array $members, array $favourites): float
    {
        if ($members === [] || $favourites === []) {
            return $this->clampPreferenceValue(0.0);
        }

        $normalisedFavourites = $this->normaliseMatchIndex($favourites);
        $withFavourites       = 0;

        foreach ($members as $media) {
            $persons = $media->getPersons();
            if ($persons === null || $persons === []) {
                continue;
            }

            $personLookup = $this->normaliseMatchIndex($persons);
            foreach ($personLookup as $normalized) {
                if (in_array($normalized, $normalisedFavourites, true)) {
                    ++$withFavourites;
                    break;
                }
            }
        }

        $coverage = $withFavourites / count($members);

        return $this->clampPreferenceValue($coverage);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function normaliseMatchIndex(array $values): array
    {
        $index = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $normalized = mb_strtolower(trim($value));
            if ($normalized === '') {
                continue;
            }

            $index[] = $normalized;
        }

        return $index;
    }

    private function clampPreferenceValue(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    private function isClusterHidden(ClusterDraft $cluster, FeedVisibilityFilter $filter): bool
    {
        $params = $cluster->getParams();

        if ($filter->hasHiddenPersons()) {
            $persons = $this->normaliseIdList($params['persons'] ?? null);
            if ($persons !== [] && $filter->intersectsPersons($persons)) {
                return true;
            }
        }

        if ($filter->hasHiddenPets()) {
            $petIds = $this->normaliseIdList($params['pet_ids'] ?? null);
            if ($petIds !== [] && $filter->intersectsPets($petIds)) {
                return true;
            }
        }

        if ($filter->hasHiddenPlaces()) {
            $place = $this->normaliseScalarString($params['place'] ?? null);
            if ($place !== null && $filter->isPlaceHidden($place)) {
                return true;
            }
        }

        if ($filter->hasHiddenDates()) {
            $dates = $this->extractTimeRangeDates($params['time_range'] ?? null);
            if ($dates !== [] && $filter->intersectsDates($dates)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function normaliseIdList(mixed $values): array
    {
        if (!is_iterable($values)) {
            return [];
        }

        $result = [];

        foreach ($values as $value) {
            $normalized = $this->normaliseScalarString($value);
            if ($normalized === null) {
                continue;
            }

            if (!in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    private function normaliseScalarString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
        } elseif ($value instanceof \Stringable) {
            $trimmed = trim((string) $value);
        } elseif (is_int($value) || is_float($value)) {
            $trimmed = trim((string) $value);
        } else {
            return null;
        }

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>
     */
    private function extractTimeRangeDates(mixed $range): array
    {
        if (!is_array($range)) {
            return [];
        }

        $dates = [];

        $days = $range['days'] ?? null;
        if (is_iterable($days)) {
            foreach ($days as $day) {
                $normalized = $this->normaliseDateString($day);
                if ($normalized === null) {
                    continue;
                }

                if (!in_array($normalized, $dates, true)) {
                    $dates[] = $normalized;
                }
            }
        }

        $singleDate = $this->normaliseDateString($range['date'] ?? null);
        if ($singleDate !== null && !in_array($singleDate, $dates, true)) {
            $dates[] = $singleDate;
        }

        $from = $this->normaliseTimestamp($range['from'] ?? null);
        $to   = $this->normaliseTimestamp($range['to'] ?? null);

        if ($from !== null && $to !== null && $to >= $from) {
            $current = (new DateTimeImmutable('@' . $from))->setTimezone(new DateTimeZone('UTC'));
            $end     = (new DateTimeImmutable('@' . $to))->setTimezone(new DateTimeZone('UTC'));

            while ($current <= $end) {
                $formatted = $current->format('Y-m-d');
                if (!in_array($formatted, $dates, true)) {
                    $dates[] = $formatted;
                }

                $current = $current->modify('+1 day');
            }
        }

        return $dates;
    }

    private function normaliseDateString(mixed $value): ?string
    {
        $text = $this->normaliseScalarString($value);
        if ($text === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $text);
        if ($date === false) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function normaliseTimestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value) || is_string($value)) {
            if (!is_numeric($value)) {
                return null;
            }

            $candidate = (int) $value;

            return $candidate > 0 ? $candidate : null;
        }

        return null;
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

    /**
     * @param list<Media> $members
     * @param array{ordered: list<int>, minimum: int} $overlay
     *
     * @return list<Media>|null
     */
    private function applyCuratedOverlay(array $members, array $overlay): ?array
    {
        if ($overlay['ordered'] === []) {
            return null;
        }

        $map = [];
        foreach ($members as $media) {
            $map[$media->getId()] = $media;
        }

        $curated = [];
        foreach ($overlay['ordered'] as $id) {
            $media = $map[$id] ?? null;
            if ($media === null) {
                continue;
            }

            $curated[] = $media;
        }

        if (count($curated) < $overlay['minimum']) {
            return null;
        }

        return $curated;
    }

    /**
     * @param array<string, scalar|array|null> $params
     *
     * @return array{ordered: list<int>, minimum: int}|null
     */
    private function resolveCuratedOverlay(array $params, FeedPersonalizationProfile $profile): ?array
    {
        $memberQuality = $params['member_quality'] ?? null;
        if (!is_array($memberQuality)) {
            return null;
        }

        $ordered = $this->normaliseMemberIdList($memberQuality['ordered'] ?? null);
        if ($ordered === []) {
            return null;
        }

        $minimum = max(
            $profile->getMinMembers(),
            $this->resolveOverlayMinimum($memberQuality),
        );

        if ($minimum <= 0) {
            $minimum = $profile->getMinMembers();
        }

        return [
            'ordered' => $ordered,
            'minimum' => $minimum,
        ];
    }

    /**
     * @param array<string, scalar|array|null> $params
     * @param array{ordered: list<int>, minimum: int} $overlay
     */
    private function markFeedOverlayUsage(array $params, array $overlay, bool $used, int $appliedCount): array
    {
        $memberQuality = $params['member_quality'] ?? null;
        if (!is_array($memberQuality)) {
            return $params;
        }

        $memberQuality['feed_overlay'] = [
            'used'            => $used,
            'minimum_total'   => $overlay['minimum'],
            'requested_count' => count($overlay['ordered']),
        ];

        if ($used) {
            $memberQuality['feed_overlay']['applied_count'] = $appliedCount;
        }

        $params['member_quality'] = $memberQuality;

        return $params;
    }

    /**
     * @param array<array-key, mixed>|null $values
     *
     * @return list<int>
     */
    private function normaliseMemberIdList(null|array $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $result = [];
        $seen   = [];

        foreach ($values as $value) {
            $id = null;
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $id = (int) $value;
            }

            if ($id === null || $id <= 0) {
                continue;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $result[]   = $id;
            $seen[$id] = true;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $memberQuality
     */
    private function resolveOverlayMinimum(array $memberQuality): int
    {
        $minimum       = null;
        $policyMinimum = null;

        $summary = $memberQuality['summary'] ?? null;
        if (is_array($summary)) {
            $policyDetails = $summary['selection_policy_details'] ?? null;
            if (is_array($policyDetails)) {
                $value = $policyDetails['minimum_total'] ?? null;
                $min   = $this->normalisePositiveInt($value);
                if ($min !== null) {
                    $policyMinimum = max($policyMinimum ?? 0, $min);
                }
            }
        }

        $memberSelection = $memberQuality['member_selection'] ?? null;
        if (is_array($memberSelection)) {
            $policy = $memberSelection['policy'] ?? null;
            if (is_array($policy)) {
                $value = $policy['minimum_total'] ?? null;
                $min   = $this->normalisePositiveInt($value);
                if ($min !== null) {
                    $policyMinimum = max($policyMinimum ?? 0, $min);
                }
            }
        }

        if ($policyMinimum !== null) {
            $minimum = $policyMinimum;
        }

        if ($minimum === null && is_array($summary)) {
            $profile = $summary['selection_profile'] ?? null;
            if (is_array($profile)) {
                $value = $profile['minimum_total'] ?? null;
                $min   = $this->normalisePositiveInt($value);
                if ($min !== null) {
                    $minimum = $min;
                }
            }
        }

        if (is_array($summary)) {
            $counts = $summary['selection_counts'] ?? null;
            if (is_array($counts)) {
                $value = $counts['curated'] ?? null;
                $min   = $this->normalisePositiveInt($value);
                if ($min !== null) {
                    $minimum = max($minimum ?? 0, $min);
                }
            }
        }

        return $minimum ?? 0;
    }

    private function normalisePositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            $candidate = (int) $value;
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, scalar|array<array-key, scalar|null>|null> $params
     */
    private function floatParam(array $params, string $key): ?float
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }

        $value = $params[$key];
        if (is_float($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function calculateAgeInDays(ClusterDraft $cluster, DateTimeImmutable $reference): ?int
    {
        $range = $cluster->getParams()['time_range'] ?? null;
        if (!is_array($range) || !array_key_exists('to', $range)) {
            return null;
        }

        $timestamp = (int) $range['to'];
        if ($timestamp <= 0) {
            return null;
        }

        $seconds = $reference->getTimestamp() - $timestamp;
        if ($seconds <= 0) {
            return 0;
        }

        return (int) floor($seconds / 86400);
    }
}
