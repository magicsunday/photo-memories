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
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionTelemetryInterface;
use MagicSunday\Memories\Service\Indexing\Support\MediaIngestionTelemetryCollector;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function is_array;
use function iterator_to_array;
use function count;

/**
 * Class DefaultMediaIngestionPipeline.
 */
final readonly class DefaultMediaIngestionPipeline implements MediaIngestionPipelineInterface
{
    /** @var list<MediaIngestionStageInterface> */
    private array $stages;

    private MediaIngestionTelemetryInterface $telemetry;

    private LoggerInterface $logger;

    /**
     * @param iterable<MediaIngestionStageInterface> $stages
     * @param list<string>                            $videoExtensions
     */
    public function __construct(
        iterable $stages,
        array $videoExtensions,
        ?MediaIngestionTelemetryInterface $telemetry = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->stages    = is_array($stages) ? $stages : iterator_to_array($stages);
        $this->telemetry = $telemetry ?? new MediaIngestionTelemetryCollector();
        $this->logger    = $logger ?? new NullLogger();

        if (count($videoExtensions) === 0) {
            $this->logger->warning('media_ingestion.video_extensions_unconfigured', [
                'videoExtensions' => $videoExtensions,
                'description'     => 'No video file extensions configured; video ingestion might skip video files.',
            ]);
        }
    }

    public function process(
        string $filepath,
        bool $force,
        bool $dryRun,
        bool $withThumbnails,
        bool $strictMime,
        OutputInterface $output,
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

        $media = $context->getMedia();

        if ($media instanceof Media) {
            $this->telemetry->recordProcessedMedia($context->getFilePath(), $media);
        }

        return $media;
    }

    public function finalize(bool $dryRun): void
    {
        $context = MediaIngestionContext::create('', false, $dryRun, false, false, new NullOutput());

        foreach ($this->stages as $stage) {
            if ($stage instanceof FinalizableMediaIngestionStageInterface) {
                $stage->finalize($context);
            }
        }

        $metrics = $this->telemetry->metrics();

        if (($metrics['ffprobe_missing'] ?? 0) > 0) {
            $this->logger->warning('media_ingestion.ffprobe_missing', [
                'missing_count' => $metrics['ffprobe_missing'],
                'message'       => 'FFprobe metadata unavailable for one or more processed videos.',
            ]);
        }

        if (($metrics['ffprobe_binary_missing'] ?? false) === true) {
            $this->logger->warning('media_ingestion.ffprobe_unavailable', [
                'message' => 'FFprobe executable could not be located; video metadata extraction was skipped.',
            ]);
        }

        $this->logger->info('media_ingestion.finalize', [
            'dryRun'  => $dryRun,
            'metrics' => $metrics,
        ]);

        $this->telemetry->reset();
    }
}
