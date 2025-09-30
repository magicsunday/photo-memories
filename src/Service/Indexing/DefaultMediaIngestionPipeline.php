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
use MagicSunday\Memories\Service\Indexing\Contract\FinalizableMediaIngestionStageInterface;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionStageInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function is_array;
use function iterator_to_array;

final class DefaultMediaIngestionPipeline implements MediaIngestionPipelineInterface
{
    /**
     * @var list<MediaIngestionStageInterface>
     */
    private readonly array $stages;

    /**
     * @param iterable<MediaIngestionStageInterface> $stages
     */
    public function __construct(iterable $stages)
    {
        $this->stages = is_array($stages) ? $stages : iterator_to_array($stages);
    }

    public function process(
        string $filepath,
        bool $force,
        bool $dryRun,
        bool $withThumbnails,
        bool $strictMime,
        OutputInterface $output
    ): ?Media {
        $context = MediaIngestionContext::create($filepath, $force, $dryRun, $withThumbnails, $strictMime, $output);

        foreach ($this->stages as $stage) {
            if ($context->isSkipped()) {
                break;
            }

            $context = $stage->process($context);
        }

        if ($context->isSkipped()) {
            return null;
        }

        return $context->getMedia();
    }

    public function finalize(bool $dryRun): void
    {
        $context = MediaIngestionContext::create('', false, $dryRun, false, false, new NullOutput());

        foreach ($this->stages as $stage) {
            if ($stage instanceof FinalizableMediaIngestionStageInterface) {
                $stage->finalize($context);
            }
        }
    }
}
