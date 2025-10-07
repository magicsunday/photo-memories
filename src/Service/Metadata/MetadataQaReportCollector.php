<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Console\Output\OutputInterface;

use function array_slice;
use function arsort;
use function count;
use function in_array;
use function sprintf;

/**
 * Accumulates QA findings during an index run and renders summary reports.
 */
final class MetadataQaReportCollector
{
    /**
     * @var array<string,int>
     */
    private array $featureCounts = [];

    /**
     * @var array<string,int>
     */
    private array $suggestionCounts = [];

    /**
     * @var array<string,int>
     */
    private array $contradictionCounts = [];

    /**
     * @var array<string,list<string>>
     */
    private array $examples = [];

    public function __construct(private int $maxExamplesPerFeature = 3)
    {
    }

    public function reset(): void
    {
        $this->featureCounts       = [];
        $this->suggestionCounts    = [];
        $this->contradictionCounts = [];
        $this->examples            = [];
    }

    public function record(Media $media, MetadataQaInspectionResult $result): void
    {
        if (!$result->hasIssues()) {
            return;
        }

        $missing = $result->getMissingFeatures();
        $path    = $media->getPath();

        foreach ($missing as $feature) {
            $this->featureCounts[$feature] = ($this->featureCounts[$feature] ?? 0) + 1;

            $examples = $this->examples[$feature] ?? [];
            if (count($examples) < $this->maxExamplesPerFeature) {
                $examples[] = $path;
            }

            $this->examples[$feature] = $examples;
        }

        foreach ($result->getSuggestions() as $suggestion) {
            $this->suggestionCounts[$suggestion] = ($this->suggestionCounts[$suggestion] ?? 0) + 1;
        }

        if (in_array('daypart', $missing, true) && $media->getTimezoneOffsetMin() === null) {
            $this->contradictionCounts['daypart_without_timezone'] = ($this->contradictionCounts['daypart_without_timezone'] ?? 0) + 1;
        }

        if (in_array('isGoldenHour', $missing, true)
            && ($media->getGpsLat() === null || $media->getGpsLon() === null)
        ) {
            $this->contradictionCounts['goldenhour_without_gps'] = ($this->contradictionCounts['goldenhour_without_gps'] ?? 0) + 1;
        }
    }

    public function buildReport(): MetadataQaReport
    {
        $featureCounts    = $this->featureCounts;
        $suggestionCounts = $this->suggestionCounts;
        $contradictions   = $this->contradictionCounts;

        arsort($featureCounts);
        arsort($suggestionCounts);
        arsort($contradictions);

        return new MetadataQaReport($featureCounts, $suggestionCounts, $contradictions, $this->examples);
    }

    public function render(OutputInterface $output): void
    {
        $report = $this->buildReport();

        if ($report->isEmpty()) {
            $output->writeln('<info>QA: Keine fehlenden Metadaten erkannt.</info>');

            return;
        }

        $output->writeln('<comment>QA-Bericht: Fehlende Metadaten</comment>');

        foreach ($report->getFeatureCounts() as $feature => $count) {
            $output->writeln(sprintf('- %s: %d Vorkommen', $feature, $count));

            $examples = $report->getExamples()[$feature] ?? [];
            if ($examples !== []) {
                $formatted = array_slice($examples, 0, $this->maxExamplesPerFeature);
                foreach ($formatted as $example) {
                    $output->writeln(sprintf('  • %s', $example));
                }
            }
        }

        if ($report->getSuggestionCounts() !== []) {
            $output->writeln('<comment>Empfohlene Maßnahmen:</comment>');
            foreach ($report->getSuggestionCounts() as $suggestion => $count) {
                $output->writeln(sprintf('- (%d×) %s', $count, $suggestion));
            }
        }

        if ($report->getContradictionCounts() !== []) {
            $output->writeln('<comment>Widersprüchliche Datenpunkte:</comment>');
            foreach ($report->getContradictionCounts() as $issue => $count) {
                $output->writeln(sprintf('- %s: %d', $issue, $count));
            }
        }
    }
}
