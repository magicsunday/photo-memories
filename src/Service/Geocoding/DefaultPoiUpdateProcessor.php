<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function function_exists;
use function is_array;
use function iterator_to_array;
use function mb_strimwidth;
use function preg_replace;
use function strlen;
use function substr;
use function trim;

final class DefaultPoiUpdateProcessor implements PoiUpdateProcessorInterface
{
    /**
     * @param positive-int $batchSize
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PoiEnsurerInterface $locationResolver,
        private readonly int $batchSize = 10,
    ) {
    }

    public function process(iterable $locations, bool $refreshPois, bool $dryRun, OutputInterface $output): PoiUpdateSummary
    {
        $items = $this->normalizeIterable($locations);
        $count = count($items);

        $progressBar = new ProgressBar($output, $count);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | Dauer: %elapsed:6s% | ETA: %estimated:-6s% | %message%');
        $progressBar->setMessage('Starte …');
        $progressBar->start();

        $processed    = 0;
        $updated      = 0;
        $networkCalls = 0;

        foreach ($items as $location) {
            $label = $location->getDisplayName() ?? $location->getCity() ?? 'Unbenannter Ort';
            $progressBar->setMessage($this->formatProgressLabel($label));

            $beforePois = $location->getPois();

            $this->locationResolver->ensurePois($location, $refreshPois);
            if ($this->locationResolver->consumeLastUsedNetwork()) {
                ++$networkCalls;
            }

            if ($beforePois !== $location->getPois()) {
                ++$updated;
            }

            ++$processed;
            $progressBar->advance();

            if (($processed % $this->batchSize) === 0 && !$dryRun) {
                $this->entityManager->flush();
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $progressBar->finish();

        $output->writeln('');
        $output->writeln('');

        return new PoiUpdateSummary($processed, $updated, $networkCalls);
    }

    /**
     * @param iterable<Location> $locations
     *
     * @return list<Location>
     */
    private function normalizeIterable(iterable $locations): array
    {
        if (is_array($locations)) {
            return $locations;
        }

        return iterator_to_array($locations, false);
    }

    private function formatProgressLabel(string $label): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($label)) ?? $label;

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($normalized, 0, 70, '…', 'UTF-8');
        }

        return strlen($normalized) > 70
            ? substr($normalized, 0, 69) . '…'
            : $normalized;
    }
}
