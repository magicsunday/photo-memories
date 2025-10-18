<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster as ClusterEntity;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Clusterer\Pipeline\PerMediaCapStage;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionPolicyProvider;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Service\Feed\MemoryFeedItem;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfile;
use MagicSunday\Memories\Service\Feed\FeedPersonalizationProfileProvider;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function max;
use function number_format;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Preview a "Für dich" feed in the console from persisted clusters.
 * Beachtet dabei die globale Limitierung pro Algorithmus aus dem Feed-Builder.
 */
#[AsCommand(
    name: 'memories:feed:preview',
    description: 'Zeigt eine Vorschau des Rückblick-Feeds.'
)]
final class FeedPreviewCommand extends Command
{
    use SelectionOverrideInputTrait;

    private const STAGE_RAW     = 'raw';
    private const STAGE_MERGED  = 'merged';
    private const STAGE_CURATED = 'curated';

    /**
     * @var list<string>
     */
    private const STAGE_OPTIONS = [
        self::STAGE_RAW,
        self::STAGE_MERGED,
        self::STAGE_CURATED,
    ];

    /**
     * @var array<string, string>
     */
    private const STAGE_LABELS = [
        self::STAGE_RAW     => 'Rohdaten',
        self::STAGE_MERGED  => 'Konsolidiert',
        self::STAGE_CURATED => 'Kuratiert',
    ];

    /**
     * @var array<string, string>
     */
    private const STAGE_EMOJIS = [
        self::STAGE_RAW     => '🔰',
        self::STAGE_MERGED  => '🔀',
        self::STAGE_CURATED => '🪄',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedBuilderInterface $feedBuilder,
        private readonly ClusterConsolidatorInterface $consolidation,
        private readonly PerMediaCapStage $perMediaCapStage,
        private readonly ClusterEntityToDraftMapper $mapper,
        private readonly SelectionPolicyProvider $selectionPolicies,
        private readonly FeedPersonalizationProfileProvider $profileProvider,
        private readonly int $defaultClusterLimit = 5000,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit-clusters',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximale Anzahl geladener Cluster',
                (string) $this->defaultClusterLimit
            )
            ->addOption(
                'min-score',
                null,
                InputOption::VALUE_REQUIRED,
                'Mindest-Score für Kandidaten (FeedBuilder-Filter)'
            )
            ->addOption(
                'per-media-cap',
                null,
                InputOption::VALUE_REQUIRED,
                'Per-Media-Cap zur Konsolidierung (0 = aus)'
            )
            ->addOption(
                'min-members',
                null,
                InputOption::VALUE_REQUIRED,
                'Mindestanzahl an Mitgliedern für Feed-Kandidaten'
            )
            ->addOption(
                'show-members',
                null,
                InputOption::VALUE_NONE,
                'Mitglieds-IDs der Items mit ausgeben'
            )
            ->addOption(
                'stage',
                null,
                InputOption::VALUE_REQUIRED,
                'Ausgabestufe (raw|merged|curated)',
                self::STAGE_CURATED,
            );

        $this->configureSelectionOverrideOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('📰 Rückblick-Feed Vorschau');

        $stageInput = $input->getOption('stage');
        $stageValue = is_string($stageInput) ? $stageInput : self::STAGE_CURATED;
        $stage      = $this->normaliseStage($stageValue);

        if ($stage === null) {
            $io->error(sprintf(
                'Unbekannte Stufe "%s". Erlaubte Werte: %s.',
                is_scalar($stageInput) ? (string) $stageInput : get_debug_type($stageInput),
                implode(', ', self::STAGE_OPTIONS),
            ));

            return Command::INVALID;
        }

        try {
            $selectionOverrides = $this->resolveSelectionOverrides($input);
            $minScore           = $this->parseFloatOption($input->getOption('min-score'), 0.0, 'min-score');
            $minMembers         = $this->parseIntOption($input->getOption('min-members'), 1, 'min-members');
            $perMediaCap        = $this->parseIntOption($input->getOption('per-media-cap'), 0, 'per-media-cap');
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $this->selectionPolicies->setRuntimeOverrides($selectionOverrides);
        $profileOverride = $this->resolvePersonalizationProfile($minScore, $minMembers);

        $limit = max(1, (int) $input->getOption('limit-clusters'));

        $qb = $this->em->createQueryBuilder()
            ->select('c')
            ->from(ClusterEntity::class, 'c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit);

        /** @var list<ClusterEntity> $entities */
        $entities = $qb->getQuery()->getResult();

        if ($entities === []) {
            $io->warning('Keine Cluster in der Datenbank gefunden.');

            return Command::SUCCESS;
        }

        // Map to drafts
        $drafts      = $this->mapper->mapMany($entities);
        $showMembers = (bool) $input->getOption('show-members');

        $io->section($this->formatStageTitle(self::STAGE_RAW));
        $this->renderDraftTable($io, $drafts, $showMembers);

        if ($stage === self::STAGE_RAW) {
            $io->success(sprintf('%d Cluster (roh) angezeigt.', count($drafts)));

            return Command::SUCCESS;
        }

        // Optional Konsolidierung (mit internem Cap etc.)
        $restorePerMediaCap = false;
        if ($perMediaCap !== null) {
            $this->perMediaCapStage->setPerMediaCapOverride($perMediaCap);
            $restorePerMediaCap = true;
        }

        try {
            $consolidated = $this->consolidation->consolidate(
                $drafts,
                static function (int $done, int $max, string $stage) use ($io): void {
                    // lightweight progress (no heavy bars to keep output tidy)
                    if ($max > 0 && ($done === $max)) {
                        $io->writeln(sprintf('  ✔ %s (%d)', $stage, $max));
                    }
                }
            );
        } finally {
            if ($restorePerMediaCap) {
                $this->perMediaCapStage->setPerMediaCapOverride(null);
            }
        }

        $io->section($this->formatStageTitle(self::STAGE_MERGED));

        if ($consolidated === []) {
            $io->warning('Keine Cluster nach der Konsolidierung.');

            return Command::SUCCESS;
        }

        $this->renderDraftTable($io, $consolidated, $showMembers);

        if ($stage === self::STAGE_MERGED) {
            $io->success(sprintf('%d Cluster (konsolidiert) angezeigt.', count($consolidated)));

            return Command::SUCCESS;
        }

        $io->section($this->formatStageTitle(self::STAGE_CURATED));
        $items = $this->feedBuilder->build($consolidated, $profileOverride);

        if ($items === []) {
            $io->warning('Der Feed ist leer (Filter/Score/Limit zu streng?).');

            return Command::SUCCESS;
        }

        $this->renderFeedTable($io, $items, $showMembers);

        $io->success(sprintf('%d Feed-Items angezeigt.', count($items)));

        return Command::SUCCESS;
    }

    private function resolvePersonalizationProfile(?float $minScore, ?int $minMembers): ?FeedPersonalizationProfile
    {
        if ($minScore === null && $minMembers === null) {
            return null;
        }

        $base = $this->profileProvider->getProfile();

        return new FeedPersonalizationProfile(
            sprintf('%s-cli', $base->getKey()),
            $minScore ?? $base->getMinScore(),
            $minMembers ?? $base->getMinMembers(),
            $base->getMaxPerDay(),
            $base->getMaxTotal(),
            $base->getMaxPerAlgorithm(),
            $base->getQualityFloor(),
            $base->getPeopleCoverageThreshold(),
            $base->getRecentDays(),
            $base->getStaleDays(),
            $base->getRecentScoreBonus(),
            $base->getStaleScorePenalty(),
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveStoryline(array $params): string
    {
        $storyline = $params['storyline'] ?? null;

        if (is_string($storyline) && $storyline !== '') {
            return $storyline;
        }

        $fallback = $params['member_quality']['summary']['selection_storyline'] ?? null;

        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        return '–';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveRawMemberCount(array $params): int
    {
        $summary = $params['member_quality']['summary'] ?? null;

        if (is_array($summary)) {
            $persisted = $summary['members_persisted'] ?? null;

            if (is_int($persisted)) {
                return $persisted;
            }

            $selectionCounts = $summary['selection_counts'] ?? null;

            if (is_array($selectionCounts)) {
                $raw = $selectionCounts['raw'] ?? null;

                if (is_int($raw)) {
                    return $raw;
                }
            }
        }

        $ordered = $params['member_quality']['ordered'] ?? null;

        if (is_array($ordered)) {
            return count($ordered);
        }

        return 0;
    }

    /**
     * @param array<string, mixed>|null $timeRange
     */
    private function formatTimeRange(?array $timeRange): string
    {
        if ($timeRange === null) {
            return '–';
        }

        $from = $this->normaliseDateTime($timeRange['from'] ?? null);
        $to   = $this->normaliseDateTime($timeRange['to'] ?? null);

        if ($from === null && $to === null) {
            return '–';
        }

        $timezone = new DateTimeZone('UTC');

        if ($from !== null) {
            $from = $from->setTimezone($timezone);
        }

        if ($to !== null) {
            $to = $to->setTimezone($timezone);
        }

        if ($from !== null && $to !== null) {
            $fromFormatted = $from->format('Y-m-d');
            $toFormatted   = $to->format('Y-m-d');

            if ($fromFormatted === $toFormatted) {
                return $fromFormatted;
            }

            return sprintf('%s → %s', $fromFormatted, $toFormatted);
        }

        $single = $from ?? $to;

        return $single->format('Y-m-d');
    }

    /**
     * @param list<ClusterDraft> $drafts
     */
    private function renderDraftTable(SymfonyStyle $io, array $drafts, bool $showMembers): void
    {
        $rows = [];
        $idx  = 0;

        foreach ($drafts as $draft) {
            ++$idx;
            $params    = $draft->getParams();
            $memberIds = $draft->getMembers();

            $row = [
                (string) $idx,
                $draft->getAlgorithm(),
                $this->resolveDraftStoryline($draft),
                (string) $this->resolveRawMemberCount($params),
                $this->resolveCuratedMemberCountDisplay($memberIds, $params, false),
                $this->formatTimeRange($this->buildDraftTimeRange($draft)),
                $this->formatDraftScore($params),
            ];

            if ($showMembers) {
                $row[] = $memberIds === [] ? '–' : implode(',', $memberIds);
            }

            $rows[] = $row;
        }

        $io->table($this->buildTableHeaders($showMembers), $rows);
    }

    /**
     * @param list<MemoryFeedItem> $items
     */
    private function renderFeedTable(SymfonyStyle $io, array $items, bool $showMembers): void
    {
        $rows = [];
        $idx  = 0;

        foreach ($items as $item) {
            ++$idx;
            $params    = $item->getParams();
            $memberIds = $item->getMemberIds();

            $row = [
                (string) $idx,
                $item->getAlgorithm(),
                $this->resolveStoryline($params),
                (string) $this->resolveRawMemberCount($params),
                $this->resolveCuratedMemberCountDisplay($memberIds, $params, true),
                $this->formatTimeRange($params['time_range'] ?? null),
                number_format($item->getScore(), 3, ',', ''),
            ];

            if ($showMembers) {
                $row[] = $memberIds === [] ? '–' : implode(',', $memberIds);
            }

            $rows[] = $row;
        }

        $io->table($this->buildTableHeaders($showMembers), $rows);
    }

    private function buildTableHeaders(bool $showMembers): array
    {
        $headers = ['#', 'Algorithmus', 'Storyline', 'Mitglieder (roh)', 'Mitglieder (kuratiert)', 'Zeitraum', 'Score'];

        if ($showMembers) {
            $headers[] = 'Mitglieder';
        }

        return $headers;
    }

    private function resolveDraftStoryline(ClusterDraft $draft): string
    {
        $storyline = $draft->getStoryline();

        if ($storyline !== '') {
            return $storyline;
        }

        return $this->resolveStoryline($draft->getParams());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function formatDraftScore(array $params): string
    {
        $score = $params['score'] ?? null;

        if (is_float($score) || is_int($score)) {
            return number_format((float) $score, 3, ',', '');
        }

        return '–';
    }

    private function buildDraftTimeRange(ClusterDraft $draft): ?array
    {
        $from = $draft->getStartAt();
        $to   = $draft->getEndAt();

        if ($from === null && $to === null) {
            return null;
        }

        return [
            'from' => $from,
            'to'   => $to,
        ];
    }

    /**
     * @param list<int>            $memberIds
     * @param array<string, mixed> $params
     */
    private function resolveCuratedMemberCountDisplay(array $memberIds, array $params, bool $fallbackToCount): string
    {
        $summary = $params['member_quality']['summary'] ?? null;

        if (is_array($summary)) {
            $selectionCounts = $summary['selection_counts'] ?? null;

            if (is_array($selectionCounts)) {
                $curated = $selectionCounts['curated'] ?? null;

                if (is_int($curated)) {
                    return (string) $curated;
                }
            }
        }

        if ($fallbackToCount) {
            return (string) count($memberIds);
        }

        return '–';
    }

    private function normaliseStage(string $stage): ?string
    {
        $normalised = strtolower(trim($stage));

        if (in_array($normalised, self::STAGE_OPTIONS, true)) {
            return $normalised;
        }

        return null;
    }

    private function formatStageTitle(string $stage): string
    {
        return sprintf(
            '%s %s (%s)',
            self::STAGE_EMOJIS[$stage] ?? '',
            self::STAGE_LABELS[$stage] ?? $stage,
            $stage,
        );
    }

    private function normaliseDateTime(DateTimeInterface|string|int|null $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value)) {
            $dateTime = DateTimeImmutable::createFromFormat('U', (string) $value);

            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime;
            }

            return null;
        }

        if ($value === null || $value === '') {
            return null;
        }

        $dateTime = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);

        if ($dateTime instanceof DateTimeImmutable) {
            return $dateTime;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
