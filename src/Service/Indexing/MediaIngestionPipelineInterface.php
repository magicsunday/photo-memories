<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Indexing;

use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Console\Output\OutputInterface;

interface MediaIngestionPipelineInterface
{
    public function process(
        string $filepath,
        bool $force,
        bool $dryRun,
        bool $withThumbnails,
        bool $strictMime,
        OutputInterface $output
    ): ?Media;

    public function finalize(bool $dryRun): void;
}
