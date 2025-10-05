<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

use function array_slice;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function number_format;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Renders a minimal, responsive HTML page with lazy-loaded image grids per card.
 */
final class HtmlFeedRenderer
{
    /**
     * @param list<array{
     *   title:string,
     *   subtitle:string,
     *   algorithm:string,
     *   group?:string,
     *   score:float,
     *   images:list<array{href:string, alt:string}>
     * }> $cards
     */
    public function render(array $cards, string $pageTitle): string
    {
        $title     = htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $itemsHtml = $this->renderCards($cards);

        $css = $this->css();

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
  <h1>Rückblick – Für dich</h1>
  <p class="sub">Statische Vorschau – lokal erzeugt</p>
</header>
<main class="grid">
$itemsHtml
</main>
<footer class="footer">
  <p>Erzeugt mit Rückblick – Stand: {date('d.m.Y H:i')}</p>
</footer>
</body>
</html>
HTML;
    }

    /**
     * @param list<array{
     *   title:string,
     *   subtitle:string,
     *   algorithm:string,
     *   group?:string,
     *   score:float,
     *   images:list<array{href:string, alt:string}>,
     *   sceneTags?:list<array{label:string, score:float}>
     * }> $cards
     */
    private function renderCards(array $cards): string
    {
        $buf = [];

        foreach ($cards as $c) {
            $t     = htmlspecialchars($c['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $st    = htmlspecialchars($c['subtitle'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $alg   = htmlspecialchars($c['algorithm'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $score = number_format($c['score'], 3, ',', '');
            $group = $c['group'] ?? null;

            $chips = [];
            if (is_string($group) && $group !== '') {
                $grp     = htmlspecialchars($group, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $chips[] = "<span class=\"chip\">$grp</span>";
            }

            $chips[] = "<span class=\"chip\">$alg</span>";
            $chips[] = "<span class=\"chip\">Score $score</span>";

            $sceneTags = $c['sceneTags'] ?? null;
            if (is_array($sceneTags)) {
                foreach (array_slice($sceneTags, 0, 3) as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }

                    $label    = $tag['label'] ?? null;
                    $scoreTag = $tag['score'] ?? null;

                    if (!is_string($label)) {
                        continue;
                    }

                    $text = $label;
                    if (is_float($scoreTag) || is_int($scoreTag)) {
                        $formatted = number_format((float) $scoreTag, 2, ',', '');
                        $text      = sprintf('%s (%s)', $label, $formatted);
                    }

                    $safe    = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $chips[] = "<span class=\"chip chip-tag\">$safe</span>";
                }
            }

            $meta = implode("\n      ", $chips);

            $images = $this->renderImages($c['images']);

            $buf[] = <<<CARD
<section class="card">
  <div class="card-head">
    <div class="titles">
      <h2>$t</h2>
      <p class="muted">$st</p>
    </div>
    <div class="meta">
      $meta
    </div>
  </div>
  <div class="thumbs">
    $images
  </div>
</section>
CARD;
        }

        return implode("\n", $buf);
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

    private function css(): string
    {
        // minimal, responsive grid; cards mit sanfter Optik
        return <<<CSS
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;background:#0b0c10;color:#e6e7ea}
.header{padding:24px 16px;border-bottom:1px solid #222}
.header h1{margin:0 0 4px;font-size:22px}
.header .sub{margin:0;color:#9aa0a6}
.grid{padding:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.card{background:#121417;border:1px solid #1e2228;border-radius:16px;padding:12px;box-shadow:0 1px 2px rgba(0,0,0,.2)}
.card-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
.titles h2{margin:0;font-size:18px}
.titles .muted{margin:2px 0 0;color:#9aa0a6;font-size:14px}
.meta{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.chip{display:inline-block;padding:2px 8px;border-radius:999px;background:#1e2228;color:#c8ccd2;font-size:12px;border:1px solid #2a2f36}
.chip-tag{background:#16242c;border-color:#27414d;color:#9bd1e3}
.thumbs{display:grid;grid-template-columns:repeat(4,1fr);gap:6px}
@media (max-width:720px){.thumbs{grid-template-columns:repeat(3,1fr)}}
@media (max-width:480px){.thumbs{grid-template-columns:repeat(2,1fr)}}
.ph{position:relative;aspect-ratio:1/1;margin:0;overflow:hidden;border-radius:10px;background:#0e1116}
.ph img{width:100%;height:100%;object-fit:cover;display:block;transform:translateZ(0)}
.footer{padding:24px 16px;border-top:1px solid #222;color:#9aa0a6}
CSS;
    }
}
