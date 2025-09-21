<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Command;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Entity\Cluster as ClusterEntity;
use MagicSunday\Memories\Service\Clusterer\ClusterConsolidationService;
use MagicSunday\Memories\Service\Feed\FeedBuilderInterface;
use MagicSunday\Memories\Support\ClusterEntityToDraftMapper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Preview a "Für dich" feed in the console from persisted clusters.
 */
#[AsCommand(
    name: 'memories:feed:preview',
    description: 'Zeigt eine Vorschau des Rückblick-Feeds.'
)]
final class FeedPreviewCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeedBuilderInterface $feedBuilder,
        private readonly ClusterConsolidationService $consolidation,
        private readonly ClusterEntityToDraftMapper $mapper,
        private readonly int $defaultClusterLimit = 5000
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
                'Mindest-Score für Kandidaten (FeedBuilder-Filter)',
                null
            )
            ->addOption(
                'per-media-cap',
                null,
                InputOption::VALUE_REQUIRED,
                'Per-Media-Cap zur Konsolidierung (0 = aus)',
                null
            )
            ->addOption(
                'show-members',
                null,
                InputOption::VALUE_NONE,
                'Mitglieds-IDs der Items mit ausgeben'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('📰 Rückblick-Feed Vorschau');

        $limit = \max(1, (int) $input->getOption('limit-clusters'));

        /** @var list<ClusterEntity> $entities */
        $entities = $this->em->createQuery(
            'SELECT c FROM MagicSunday\Memories\Entity\Cluster c ORDER BY c.createdAt DESC'
        )
            ->setMaxResults($limit)
            ->getResult();

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
                    $io->writeln(\sprintf('  ✔ %s (%d)', $stage, $max));
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
        $rows = [];
        $showMembers = (bool) $input->getOption('show-members');
        $idx = 0;

        foreach ($items as $it) {
            $idx++;
            $rows[] = [
                (string) $idx,
                $it->getAlgorithm(),
                $it->getTitle(),
                $it->getSubtitle(),
                \number_format($it->getScore(), 3, ',', ''),
                (string) \count($it->getMemberIds()),
                $it->getCoverMediaId() !== null ? (string) $it->getCoverMediaId() : '–',
                $showMembers ? \implode(',', $it->getMemberIds()) : '–',
            ];
        }

        $io->table(
            ['#', 'Strategie', 'Titel', 'Untertitel', 'Score', 'Anz.', 'Cover-ID', $showMembers ? 'Mitglieder' : '–'],
            $rows
        );

        $io->success(\sprintf('%d Feed-Items angezeigt.', \count($items)));
        return Command::SUCCESS;
    }
}
