<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Service\Clusterer\Title\TitleTemplateProvider;

/**
 * Renders titles/subtitles using YAML templates + params (iOS-like).
 */
final readonly class SmartTitleGenerator implements TitleGeneratorInterface
{
    public function __construct(private TitleTemplateProvider $provider)
    {

    }

    public function makeTitle(ClusterDraft $cluster, string $locale = 'de'): string
    {
        $tpl = $this->provider->find($cluster->getAlgorithm(), $locale);
        $raw = $tpl['title'] ?? $this->fallbackTitle($cluster);
        return $this->render($raw, $cluster);
    }

    public function makeSubtitle(ClusterDraft $cluster, string $locale = 'de'): string
    {
        $tpl = $this->provider->find($cluster->getAlgorithm(), $locale);
        $raw = $tpl['subtitle'] ?? $this->fallbackSubtitle($cluster);
        return $this->render($raw, $cluster);
    }

    /** Very small moustache-like renderer for {{ keys }} from params */
    private function render(string $template, ClusterDraft $cluster): string
    {
        $p = $cluster->getParams();

        // Common computed helpers
        $p['date_range'] ??= $this->formatRange($p['time_range'] ?? null);
        $p['start_date'] ??= $this->formatDate(($p['time_range']['from'] ?? null));
        $p['end_date'] ??= $this->formatDate(($p['time_range']['to'] ?? null));

        return \preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', static function (array $m) use ($p): string {
            $key = $m[1];
            $val = $p[$key] ?? null;
            if (\is_scalar($val)) {
                return (string) $val;
            }

            return '';
        }, $template) ?? $template;
    }

    private function formatRange(mixed $tr): string
    {
        if (\is_array($tr) && isset($tr['from'], $tr['to'])) {
            $from = (int) $tr['from'];
            $to   = (int) $tr['to'];
            if ($from > 0 && $to > 0) {
                $df = (new DateTimeImmutable('@'.$from))->setTimezone(new DateTimeZone('Europe/Berlin'));
                $dt = (new DateTimeImmutable('@'.$to))->setTimezone(new DateTimeZone('Europe/Berlin'));
                if ($df->format('Y-m-d') === $dt->format('Y-m-d')) {
                    return $df->format('d.m.Y');
                }

                if ($df->format('Y') === $dt->format('Y')) {
                    return $df->format('d.m.').' – '.$dt->format('d.m.Y');
                }

                return $df->format('d.m.Y').' – '.$dt->format('d.m.Y');
            }
        }

        return '';
    }

    private function formatDate(mixed $ts): string
    {
        $t = \is_scalar($ts) ? (int) $ts : 0;
        return $t > 0 ? (new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y') : '';
    }

    private function fallbackTitle(ClusterDraft $cluster): string
    {
        return $cluster->getParams()['label'] ?? 'Rückblick';
    }

    private function fallbackSubtitle(ClusterDraft $cluster): string
    {
        return $this->formatRange($cluster->getParams()['time_range'] ?? null);
    }
}
