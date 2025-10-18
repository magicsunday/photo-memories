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
use MagicSunday\Memories\Service\Feed\FeedExportStage;

use function htmlspecialchars;
use function implode;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Renders a minimal, stage-aware HTML page with navigation between pipeline levels.
 */
final class HtmlFeedRenderer
{
    /**
     * @param array<string, array{
     *   cards:list<array{
     *     title:string,
     *     subtitle:string,
     *     chips:list<array{label:string, variant:string}>,
     *     images:list<array{href:string, alt:string}>,
     *     details:list<string>
     *   }>,
     *   summary:string,
     *   emptyMessage:string
     * }> $stages
     */
    public function render(FeedExportStage $activeStage, array $stages, string $pageTitle, DateTimeImmutable $generatedAt): string
    {
        $title     = htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $generated = htmlspecialchars($generatedAt->format('d.m.Y H:i'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $generatedIso = htmlspecialchars($generatedAt->format(DateTimeImmutable::ATOM), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $nav      = $this->renderNavigation($stages, $activeStage);
        $sections = $this->renderStageSections($stages, $activeStage);
        $css      = $this->css();

        return <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title</title>
<style>$css</style>
</head>
<body>
<header class="header">
  <div>
    <h1>Rückblick – Für dich</h1>
    <p class="sub">Statische Vorschau – lokal erzeugt</p>
  </div>
  <div class="generated" data-generated="$generatedIso">Stand: $generated</div>
</header>
<nav class="stage-nav">
$nav
</nav>
<main class="stage-content">
$sections
</main>
<footer class="footer">
  <p>Erzeugt mit Rückblick – Stand: $generated</p>
</footer>
</body>
</html>
HTML;
    }

    /**
     * @param array<string, array{summary:string}> $stages
     */
    private function renderNavigation(array $stages, FeedExportStage $activeStage): string
    {
        $items = [];

        foreach (FeedExportStage::cases() as $stage) {
            $payload = $stages[$stage->value] ?? ['summary' => '0'];
            $summary = htmlspecialchars((string) ($payload['summary'] ?? '0'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $label   = htmlspecialchars($stage->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $anchor  = htmlspecialchars($stage->anchor(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $classes = ['stage-nav__item'];
            $aria    = '';

            if ($stage === $activeStage) {
                $classes[] = 'is-active';
                $aria       = ' aria-current="page"';
            }

            $items[] = sprintf(
                '<li class="%s"><a href="#%s"%s>%s</a><span class="stage-nav__meta">%s</span></li>',
                implode(' ', $classes),
                $anchor,
                $aria,
                $label,
                $summary,
            );
        }

        return '<ul class="stage-nav__list">' . implode('', $items) . '</ul>';
    }

    /**
     * @param array<string, array{cards:list<array{title:string, subtitle:string, chips:list<array{label:string, variant:string}>, images:list<array{href:string, alt:string}>, details:list<string>}>, emptyMessage:string}> $stages
     */
    private function renderStageSections(array $stages, FeedExportStage $activeStage): string
    {
        $sections = [];

        foreach (FeedExportStage::cases() as $stage) {
            $payload      = $stages[$stage->value] ?? ['cards' => [], 'emptyMessage' => 'Keine Inhalte für diese Stufe.'];
            $cards        = $payload['cards'] ?? [];
            $emptyMessage = (string) ($payload['emptyMessage'] ?? 'Keine Inhalte für diese Stufe.');

            $content = $cards !== []
                ? $this->renderCards($cards)
                : $this->renderEmptyState($emptyMessage);

            $classes = ['stage-section'];
            if ($stage === $activeStage) {
                $classes[] = 'is-active';
            }

            $sections[] = sprintf(
                '<section id="%s" class="%s"><header class="stage-section__header"><h2>%s</h2><p class="stage-section__desc">%s</p></header>%s</section>',
                htmlspecialchars($stage->anchor(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                implode(' ', $classes),
                htmlspecialchars($stage->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($stage->description(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $content,
            );
        }

        return implode("\n", $sections);
    }

    /**
     * @param list<array{
     *   title:string,
     *   subtitle:string,
     *   chips:list<array{label:string, variant:string}>,
     *   images:list<array{href:string, alt:string}>,
     *   details:list<string>
     * }> $cards
     */
    private function renderCards(array $cards): string
    {
        $items = [];

        foreach ($cards as $card) {
            $title    = htmlspecialchars($card['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subtitle = htmlspecialchars($card['subtitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $chips    = $this->renderChips($card['chips']);
            $images   = $this->renderImages($card['images']);
            $details  = $this->renderDetails($card['details']);

            $items[] = <<<CARD
<div class="card">
  <div class="card-head">
    <div class="titles">
      <h3>$title</h3>
      <p class="muted">$subtitle</p>
    </div>
    <div class="meta">
      $chips
    </div>
  </div>
  <div class="thumbs">
    $images
  </div>
  $details
</div>
CARD;
        }

        return '<div class="stage-section__grid">' . implode("\n", $items) . '</div>';
    }

    /**
     * @param list<array{label:string, variant:string}> $chips
     */
    private function renderChips(array $chips): string
    {
        if ($chips === []) {
            return '';
        }

        $elements = [];

        foreach ($chips as $chip) {
            $label   = htmlspecialchars($chip['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $variant = $chip['variant'] ?? 'default';

            $classes = ['chip'];
            if ($variant === 'curated') {
                $classes[] = 'chip-curated';
            } elseif ($variant === 'tag') {
                $classes[] = 'chip-tag';
            } elseif ($variant === 'stage') {
                $classes[] = 'chip-stage';
            }

            $elements[] = sprintf('<span class="%s">%s</span>', implode(' ', $classes), $label);
        }

        return implode('', $elements);
    }

    /**
     * @param list<array{href:string, alt:string}> $images
     */
    private function renderImages(array $images): string
    {
        $out = [];
        foreach ($images as $img) {
            $href = htmlspecialchars($img['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $alt  = htmlspecialchars($img['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $out[] = <<<IMG
<figure class="ph">
  <img loading="lazy" decoding="async" src="$href" alt="$alt">
</figure>
IMG;
        }

        return implode("\n", $out);
    }

    /**
     * @param list<string> $details
     */
    private function renderDetails(array $details): string
    {
        if ($details === []) {
            return '';
        }

        $items = [];
        foreach ($details as $detail) {
            $items[] = '<li>' . htmlspecialchars($detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }

        return '<ul class="details">' . implode('', $items) . '</ul>';
    }

    private function renderEmptyState(string $message): string
    {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="stage-section__empty">' . $safe . '</div>';
    }

    private function css(): string
    {
        return <<<CSS
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;background:#0b0c10;color:#e6e7ea;display:flex;flex-direction:column;min-height:100vh}
.header{padding:24px 16px;border-bottom:1px solid #222;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;justify-content:space-between}
.header h1{margin:0;font-size:22px}
.header .sub{margin:0;color:#9aa0a6;font-size:14px}
.generated{color:#9aa0a6;font-size:13px}
.stage-nav{border-bottom:1px solid #222;background:#111317;position:sticky;top:0;z-index:10}
.stage-nav__list{margin:0;padding:12px 16px;list-style:none;display:flex;gap:12px;flex-wrap:wrap}
.stage-nav__item{display:flex;align-items:center;gap:8px}
.stage-nav__item a{display:inline-block;padding:6px 12px;border-radius:999px;background:#1e2228;color:#c8ccd2;text-decoration:none;font-size:14px;border:1px solid #2a2f36;transition:background .2s ease,color .2s ease}
.stage-nav__item a:hover{background:#2a2f36;color:#fff}
.stage-nav__item.is-active a{background:#2a3642;border-color:#3a5168;color:#fff}
.stage-nav__meta{color:#9aa0a6;font-size:12px}
.stage-content{flex:1;padding:24px 16px;display:flex;flex-direction:column;gap:32px}
.stage-section{display:flex;flex-direction:column;gap:16px}
.stage-section__header h2{margin:0;font-size:20px}
.stage-section__desc{margin:4px 0 0;color:#9aa0a6;font-size:14px;max-width:720px}
.stage-section__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.stage-section__empty{padding:32px;border:1px dashed #2a2f36;border-radius:12px;background:#111317;color:#9aa0a6;font-size:14px}
.card{background:#121417;border:1px solid #1e2228;border-radius:16px;padding:12px;box-shadow:0 1px 2px rgba(0,0,0,.2);display:flex;flex-direction:column;gap:12px}
.card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.titles h3{margin:0;font-size:18px}
.titles .muted{margin:2px 0 0;color:#9aa0a6;font-size:14px}
.meta{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.chip{display:inline-block;padding:2px 8px;border-radius:999px;background:#1e2228;color:#c8ccd2;font-size:12px;border:1px solid #2a2f36}
.chip-curated{background:#1c261a;border-color:#324b2d;color:#9dd48f}
.chip-tag{background:#16242c;border-color:#27414d;color:#9bd1e3}
.chip-stage{background:#2a3642;border-color:#3a5168;color:#d0e4ff}
.thumbs{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
@media (max-width:720px){.thumbs{grid-template-columns:repeat(3,1fr)}}
@media (max-width:480px){.thumbs{grid-template-columns:repeat(2,1fr)}}
.ph{position:relative;aspect-ratio:1/1;margin:0;overflow:hidden;border-radius:10px;background:#0e1116}
.ph img{width:100%;height:100%;object-fit:cover;display:block;transform:translateZ(0)}
.details{margin:0;padding:0 0 0 18px;color:#c8ccd2;font-size:13px}
.details li{margin-bottom:4px}
.footer{padding:24px 16px;border-top:1px solid #222;color:#9aa0a6;font-size:14px}
CSS;
    }
}
