<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Feed;

enum FeedExportStage: string
{
    case Raw = 'raw';
    case Merged = 'merged';
    case Curated = 'curated';

    public function label(): string
    {
        return match ($this) {
            self::Raw => 'Roh-Cluster',
            self::Merged => 'Konsolidierte Drafts',
            self::Curated => 'Kuratiertes Feed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Raw => 'Direkt aus der Datenbank geladene Cluster ohne weitere Verarbeitung.',
            self::Merged => 'Nach dem Merge/Consolidation-Schritt bereinigte Drafts.',
            self::Curated => 'Endgültige Feed-Items nach Ranking, Kuratierung und Limits.',
        };
    }

    public function anchor(): string
    {
        return 'stage-' . $this->value;
    }
}
