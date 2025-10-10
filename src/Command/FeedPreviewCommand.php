<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Selection\SelectionProfileProvider;
use MagicSunday\Memories\Entity\Cluster as ClusterEntity;
use MagicSunday\Memories\Service\Clusterer\Contract\ClusterConsolidatorInterface;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_slice;
use function count;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function number_format;
use function sprintf;

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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedBuilderInterface $feedBuilder,
        private readonly ClusterConsolidatorInterface $consolidation,
        private readonly ClusterEntityToDraftMapper $mapper,
        private readonly SelectionProfileProvider $selectionProfiles,
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
                'show-members',
                null,
                InputOption::VALUE_NONE,
                'Mitglieds-IDs der Items mit ausgeben'
            );

        $this->configureSelectionOverrideOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('📰 Rückblick-Feed Vorschau');

        try {
            $selectionOverrides = $this->resolveSelectionOverrides($input);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $this->selectionProfiles->setRuntimeOverrides($selectionOverrides);

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
        $drafts = $this->mapper->mapMany($entities);

        // Optional Konsolidierung (mit internem Cap etc.)
        $io->section('Konsolidierung');
        $consolidated = $this->consolidation->consolidate(
            $drafts,
            static function (int $done, int $max, string $stage) use ($io): void {
                // lightweight progress (no heavy bars to keep output tidy)
                if ($max > 0 && ($done === $max)) {
                    $io->writeln(sprintf('  ✔ %s (%d)', $stage, $max));
                }
            }
        );

        if ($consolidated === []) {
            $io->warning('Keine Cluster nach der Konsolidierung.');

            return Command::SUCCESS;
        }

        // Build feed
        $io->section('Feed erzeugen');
        $items = $this->feedBuilder->build($consolidated);

        if ($items === []) {
            $io->warning('Der Feed ist leer (Filter/Score/Limit zu streng?).');

            return Command::SUCCESS;
        }

        // Render table
        $rows        = [];
        $showMembers = (bool) $input->getOption('show-members');
        $idx         = 0;

        foreach ($items as $it) {
            ++$idx;
            $params    = $it->getParams();
            $tagColumn = $this->formatSceneTags($params['scene_tags'] ?? null);
            $rows[]    = [
                (string) $idx,
                $it->getAlgorithm(),
                $it->getTitle(),
                $it->getSubtitle(),
                number_format($it->getScore(), 3, ',', ''),
                (string) count($it->getMemberIds()),
                $it->getCoverMediaId() !== null ? (string) $it->getCoverMediaId() : '–',
                $showMembers ? implode(',', $it->getMemberIds()) : '–',
                $tagColumn,
            ];
        }

        $headers   = ['#', 'Strategie', 'Titel', 'Untertitel', 'Score', 'Anz.', 'Cover-ID'];
        $headers[] = $showMembers ? 'Mitglieder' : '–';
        $headers[] = 'Tags';

        $io->table($headers, $rows);

        $io->success(sprintf('%d Feed-Items angezeigt.', count($items)));

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>|bool|float|int|string|null>|bool|float|int|string|null $value
     */
    private function formatSceneTags(array|bool|float|int|string|null $value): string
    {
        if (!is_array($value)) {
            return '–';
        }

        $parts = [];
        foreach (array_slice($value, 0, 3) as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $label = $tag['label'] ?? null;
            $score = $tag['score'] ?? null;

            if (!is_string($label)) {
                continue;
            }

            $text = $label;
            if (is_float($score) || is_int($score)) {
                $formatted = number_format((float) $score, 2, ',', '');
                $text      = sprintf('%s (%s)', $label, $formatted);
            }

            $parts[] = $text;
        }

        if ($parts === []) {
            return '–';
        }

        return implode(', ', $parts);
    }
}
