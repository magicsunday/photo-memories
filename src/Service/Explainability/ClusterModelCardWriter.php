<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Explainability;

use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Selection\SelectionTelemetry;

use function array_map;
use function array_sum;
use function count;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function ksort;
use function mkdir;
use function number_format;
use function rtrim;
use function sha1;
use function sort;
use function sprintf;
use function trim;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Generates lightweight HTML model cards for consolidated clusters.
 */
final class ClusterModelCardWriter
{
    private const REJECTION_LABELS = [
        SelectionTelemetry::REASON_TIME_GAP => 'Zeitabstand',
        SelectionTelemetry::REASON_DAY_QUOTA => 'Tag-Limit',
        SelectionTelemetry::REASON_TIME_SLOT => 'Zeitslot',
        SelectionTelemetry::REASON_STAYPOINT => 'Aufenthaltsort-Limit',
        SelectionTelemetry::REASON_PHASH => 'Ähnlichkeit (pHash)',
        SelectionTelemetry::REASON_SCENE => 'Szenen-Balance',
        SelectionTelemetry::REASON_ORIENTATION => 'Ausrichtung',
        SelectionTelemetry::REASON_PEOPLE => 'Personen-Balance',
    ];

    public function __construct(private readonly string $outputDirectory)
    {
    }

    /**
     * @param list<ClusterDraft> $drafts
     *
     * @return list<string> absolute file paths of generated model cards
     */
    public function writeCards(array $drafts): array
    {
        if ($drafts === []) {
            return [];
        }

        $directory = rtrim($this->outputDirectory, DIRECTORY_SEPARATOR);
        if ($directory === '') {
            return [];
        }

        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $paths = [];
        foreach ($drafts as $draft) {
            $fingerprint = $this->fingerprint($draft);
            $filePath    = $directory . DIRECTORY_SEPARATOR . sprintf('cluster-%s.html', $fingerprint);
            $html        = $this->renderModelCard($draft, $fingerprint);

            file_put_contents($filePath, $html);
            $paths[] = $filePath;
        }

        return $paths;
    }

    private function fingerprint(ClusterDraft $draft): string
    {
        $members = $draft->getMembers();
        sort($members);

        return sha1(implode(',', array_map(static fn (int $member): string => (string) $member, $members)));
    }

    private function renderModelCard(ClusterDraft $draft, string $fingerprint): string
    {
        $params           = $draft->getParams();
        $memberSelection  = $this->normaliseArray($params['member_selection'] ?? null);
        $memberQuality    = $this->normaliseArray($params['member_quality'] ?? null);
        $selectionSummary = $this->normaliseArray($memberQuality['summary'] ?? null);
        $selectionTelemetry = $this->normaliseArray($selectionSummary['selection_telemetry'] ?? ($memberSelection['selection_telemetry'] ?? null));
        $policyDetails    = $this->normaliseArray($memberSelection['policy'] ?? ($selectionTelemetry['policy'] ?? null));
        $rejectionCounts  = $this->normaliseArray($selectionSummary['rejection_counts'] ?? ($selectionTelemetry['rejection_counts'] ?? ($memberSelection['rejection_counts'] ?? null)));
        $drops            = $this->normaliseArray($selectionTelemetry['drops'] ?? []);
        $merges           = $this->normaliseArray(($params['meta']['merges'] ?? []) ?? []);
        $scores           = $this->collectScoreDetails($params, $selectionSummary);
        $mmr              = $this->normaliseArray($selectionTelemetry['mmr'] ?? null);

        $header = [
            'Fingerprint'             => $fingerprint,
            'Algorithmus'             => $draft->getAlgorithm(),
            'Storyline'               => $draft->getStoryline(),
            'Gruppe'                  => $this->stringOrNull($params['group'] ?? null) ?? 'unbekannt',
            'Mitglieder (kuratiert)'  => $selectionSummary['curated_count'] ?? ($memberSelection['counts']['curated'] ?? count($draft->getMembers())),
            'Mitglieder (roh)'        => $selectionSummary['counts']['raw'] ?? ($memberSelection['counts']['raw'] ?? count($draft->getMembers())),
            'Score'                   => $this->formatScore($params['score'] ?? null),
        ];

        $strategies = $this->buildStrategiesSection($policyDetails, $params);
        $mergeTable = $this->renderMergeTable($merges);
        $scoreTable = $this->renderKeyValueTable($scores);
        $rejectionTable = $this->renderRejectionTable($rejectionCounts, $drops);
        $keyPhoto = $this->buildKeyPhotoNarrative($draft, $selectionSummary, $policyDetails);
        $mmrSection = $this->renderMmrSection($mmr);
        $featuresJson = $this->renderDecisionFeaturesJson($selectionTelemetry);

        $title = sprintf('Model Card – %s (%s)', $draft->getStoryline(), $fingerprint);

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{$this->escape($title)}</title>
    <style>
        body { font-family: "Segoe UI", sans-serif; color: #1f2933; background: #f8fafc; margin: 2rem; }
        h1 { font-size: 2rem; margin-bottom: 1rem; }
        h2 { font-size: 1.4rem; margin-top: 2rem; border-bottom: 1px solid #cbd5e1; padding-bottom: 0.3rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #cbd5e1; padding: 0.5rem; text-align: left; }
        th { background: #e2e8f0; }
        .muted { color: #64748b; }
        pre { background: #0f172a; color: #e2e8f0; padding: 1rem; overflow: auto; border-radius: 0.25rem; }
        ul { padding-left: 1.5rem; }
    </style>
</head>
<body>
    <h1>{$this->escape($title)}</h1>
    {$this->renderKeyValueTable($header)}

    <h2>Verwendete Strategien &amp; Policy</h2>
    {$strategies}

    <h2>Merge-Historie</h2>
    {$mergeTable}

    <h2>Scores &amp; Kennzahlen</h2>
    {$scoreTable}

    <h2>Abgelehnte Medien (mit Gründen)</h2>
    {$rejectionTable}

    <h2>Key-Photo-Begründung</h2>
    <p>{$this->escape($keyPhoto)}</p>

    <h2>MMR-Re-Ranking-Verlauf</h2>
    {$mmrSection}

    <h2>decision_features (JSON)</h2>
    {$featuresJson}
</body>
</html>
HTML;
    }

    /**
     * @param array<int|string, mixed>|null $value
     *
     * @return array<string, mixed>
     */
    private function normaliseArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalised = [];
        foreach ($value as $key => $inner) {
            if (is_string($key)) {
                $normalised[$key] = $inner;
            }
        }

        return $normalised;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function renderKeyValueTable(array $data): string
    {
        if ($data === []) {
            return '<p class="muted">Keine Daten vorhanden.</p>';
        }

        $rows = [];
        foreach ($data as $label => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }

            $rows[] = sprintf(
                '<tr><th scope="row">%s</th><td>%s</td></tr>',
                $this->escape((string) $label),
                $this->escape((string) $value)
            );
        }

        return '<table><tbody>' . implode('', $rows) . '</tbody></table>';
    }

    private function buildStrategiesSection(array $policyDetails, array $params): string
    {
        $items = [];

        if ($policyDetails !== []) {
            $rows = [];
            ksort($policyDetails);
            foreach ($policyDetails as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }

                $rows[] = sprintf('<li><strong>%s:</strong> %s</li>', $this->escape((string) $key), $this->escape((string) $value));
            }

            if ($rows !== []) {
                $items[] = '<h3>Policy</h3><ul>' . implode('', $rows) . '</ul>';
            }
        }

        $strategies = $params['meta']['strategies'] ?? null;
        if (is_array($strategies) && $strategies !== []) {
            $rows = [];
            foreach ($strategies as $strategy) {
                if (!is_string($strategy) || trim($strategy) === '') {
                    continue;
                }

                $rows[] = '<li>' . $this->escape($strategy) . '</li>';
            }

            if ($rows !== []) {
                $items[] = '<h3>Strategien</h3><ul>' . implode('', $rows) . '</ul>';
            }
        }

        if ($items === []) {
            return '<p class="muted">Keine zusätzlichen Strategien dokumentiert.</p>';
        }

        return implode('', $items);
    }

    private function renderMergeTable(array $merges): string
    {
        if ($merges === []) {
            return '<p class="muted">Keine Zusammenführungen aufgezeichnet.</p>';
        }

        $rows = [];
        foreach ($merges as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $source = $this->stringOrNull($entry['source'] ?? null) ?? 'unbekannt';
            $decision = $this->stringOrNull($entry['decision'] ?? null) ?? 'n/a';
            $overlap = $this->stringOrNull($entry['overlap'] ?? null) ?? 'n/a';
            $reason  = $this->stringOrNull($entry['reason'] ?? null) ?? '';

            if (is_numeric($overlap)) {
                $overlap = number_format((float) $overlap, 2);
            }

            $rows[] = sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->escape($source),
                $this->escape($decision),
                $this->escape($overlap),
                $this->escape($reason)
            );
        }

        if ($rows === []) {
            return '<p class="muted">Keine Zusammenführungen aufgezeichnet.</p>';
        }

        return '<table><thead><tr><th>Quelle</th><th>Entscheidung</th><th>Überlappung</th><th>Begründung</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table>';
    }

    private function collectScoreDetails(array $params, array $selectionSummary): array
    {
        $scores = [];

        $scores['Cluster-Score'] = $this->formatScore($params['score'] ?? null);

        $selectionCounts = $this->normaliseArray($selectionSummary['selection_counts'] ?? []);
        if ($selectionCounts !== []) {
            $scores['Auswahl (roh/kuratiert)'] = sprintf(
                '%d / %d',
                (int) ($selectionCounts['raw'] ?? 0),
                (int) ($selectionCounts['curated'] ?? 0)
            );
        }

        $hashDistance = $selectionSummary['selection_spacing']['average_seconds'] ?? ($selectionSummary['avg_phash_distance'] ?? null);
        if (is_numeric($hashDistance)) {
            $scores['Durchschnittlicher pHash-Abstand'] = number_format((float) $hashDistance, 4);
        }

        $spacing = $selectionSummary['selection_spacing'] ?? null;
        if (is_array($spacing) && isset($spacing['average_seconds'])) {
            $scores['Durchschnittlicher Zeitabstand (s)'] = number_format((float) $spacing['average_seconds'], 2);
        }

        $memberQuality = $this->normaliseArray($params['member_quality']['summary'] ?? null);
        if ($memberQuality !== []) {
            $scores['Mitgliedsqualität'] = [
                'ordered' => $memberQuality['ordered'] ?? [],
                'selection_policy' => $memberQuality['selection_policy'] ?? null,
            ];
        }

        return $scores;
    }

    private function renderRejectionTable(array $rejectionCounts, array $drops): string
    {
        if ($rejectionCounts === [] && $drops === []) {
            return '<p class="muted">Keine Ablehnungen dokumentiert.</p>';
        }

        $rows = [];
        foreach ($rejectionCounts as $reason => $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $label = self::REJECTION_LABELS[$reason] ?? (string) $reason;
            $rows[] = sprintf(
                '<tr><td>%s</td><td>%s</td></tr>',
                $this->escape($label),
                $this->escape((string) $value)
            );
        }

        if ($drops !== []) {
            foreach ($drops as $category => $value) {
                if (!is_numeric($value)) {
                    continue;
                }

                $rows[] = sprintf(
                    '<tr><td>%s</td><td>%s</td></tr>',
                    $this->escape('Drops: ' . (string) $category),
                    $this->escape((string) $value)
                );
            }
        }

        if ($rows === []) {
            return '<p class="muted">Keine Ablehnungen dokumentiert.</p>';
        }

        return '<table><thead><tr><th>Grund</th><th>Anzahl</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table>';
    }

    private function buildKeyPhotoNarrative(ClusterDraft $draft, array $selectionSummary, array $policyDetails): string
    {
        $cover = $draft->getCoverMediaId();
        if ($cover === null) {
            return 'Kein Schlüsselbild ausgewählt.';
        }

        $policyProfile = $policyDetails['profile'] ?? 'unbekannt';
        $storyline     = $selectionSummary['selection_storyline'] ?? $draft->getStoryline();
        $counts        = $this->normaliseArray($selectionSummary['selection_counts'] ?? []);

        $curated = (int) ($counts['curated'] ?? 0);
        $raw     = (int) ($counts['raw'] ?? 0);

        return sprintf(
            'Cover #%d basierend auf Policy %s (%s). Auswahl: %d von %d Medien.',
            $cover,
            $policyProfile,
            $storyline,
            $curated,
            max($curated, $raw)
        );
    }

    private function renderMmrSection(array $mmr): string
    {
        if ($mmr === []) {
            return '<p class="muted">Keine MMR-Telemetrie verfügbar.</p>';
        }

        $summaryTable = $this->renderKeyValueTable([
            'λ' => $mmr['lambda'] ?? 'n/a',
            'Ähnlichkeit (Floor)' => $mmr['similarity_floor'] ?? 'n/a',
            'Ähnlichkeit (Cap)'   => $mmr['similarity_cap'] ?? 'n/a',
            'Max. Kandidaten'     => $mmr['max_considered'] ?? 'n/a',
            'Poolgröße'           => $mmr['pool_size'] ?? 'n/a',
            'Ausgewählt'          => $this->formatSelectedList($mmr['selected'] ?? []),
        ]);

        $iterations = $mmr['iterations'] ?? [];
        if (!is_array($iterations) || $iterations === []) {
            return $summaryTable;
        }

        $iterationRows = [];
        foreach ($iterations as $iteration) {
            if (!is_array($iteration)) {
                continue;
            }

            $step     = $iteration['step'] ?? 'n/a';
            $selected = $iteration['selected'] ?? 'n/a';
            $evaluations = $iteration['evaluations'] ?? [];
            if (is_array($evaluations)) {
                $evaluations = json_encode($evaluations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $iterationRows[] = sprintf(
                '<tr><td>%s</td><td>%s</td><td><pre>%s</pre></td></tr>',
                $this->escape((string) $step),
                $this->escape((string) $selected),
                $this->escape((string) $evaluations)
            );
        }

        if ($iterationRows === []) {
            return $summaryTable;
        }

        $iterationTable = '<table><thead><tr><th>Iteration</th><th>Gewählt</th><th>Evaluierungen</th></tr></thead><tbody>'
            . implode('', $iterationRows)
            . '</tbody></table>';

        return $summaryTable . $iterationTable;
    }

    private function renderDecisionFeaturesJson(array $selectionTelemetry): string
    {
        if ($selectionTelemetry === []) {
            return '<p class="muted">Keine zusätzlichen decision_features verfügbar.</p>';
        }

        $json = json_encode($selectionTelemetry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '<p class="muted">decision_features konnten nicht serialisiert werden.</p>';
        }

        return '<pre>' . $this->escape($json) . '</pre>';
    }

    private function formatScore(mixed $value): string
    {
        if (is_numeric($value)) {
            return number_format((float) $value, 3);
        }

        return 'n/a';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }

    private function formatSelectedList(mixed $value): string
    {
        if (!is_array($value)) {
            return 'n/a';
        }

        $items = [];
        foreach ($value as $id) {
            if (!is_int($id)) {
                continue;
            }

            $items[] = (string) $id;
        }

        return $items === [] ? 'n/a' : implode(', ', $items);
    }
}
