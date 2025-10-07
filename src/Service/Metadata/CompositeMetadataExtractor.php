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
use MagicSunday\Memories\Support\IndexLogEntry;
use MagicSunday\Memories\Support\IndexLogHelper;
use RuntimeException;
use Throwable;

use function hrtime;
use function is_file;
use function is_string;
use function mime_content_type;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/**
 * Orchestrates a sequence of specialized extractors.
 * Keeps IndexCommand unchanged: it still depends on MetadataExtractorInterface.
 */
final readonly class CompositeMetadataExtractor implements MetadataExtractorInterface
{
    /**
     * @param SingleMetadataExtractorInterface[] $extractors ordered list; cheap/likely first
     */
    public function __construct(
        private array $extractors,
        private MetadataExtractorPipelineConfiguration $configuration,
        private MetadataExtractorTelemetry $telemetry,
    ) {
    }

    /**
     * Runs all supporting extractors sequentially to enrich the given media metadata.
     * Unsupported extractors are skipped, while supported ones merge their output into the same
     * Media instance so that later extractors can extend earlier results. The method mutates the
     * supplied entity by guessing a MIME type when none is present before any extractor executes.
     *
     * @param string $filepath absolute path to the media file currently processed
     * @param Media  $media    media entity to populate; receives a MIME type guess when missing
     *
     * @return Media media entity that contains the aggregated metadata from all supporting extractors
     */
    public function extract(string $filepath, Media $media): Media
    {
        $this->ensureMimeType($filepath, $media);

        foreach ($this->extractors as $extractor) {
            if ($this->configuration->isEnabled($extractor) === false) {
                $reason = $this->configuration->disabledReason($extractor);
                $description = $this->configuration->describeExtractor($extractor);
                $message = sprintf('Extractor %s deaktiviert.', $description);

                if ($reason !== null) {
                    $message = sprintf('%s Grund: %s', $message, $reason);
                }

                $context = ['extractor' => $description];
                if ($reason !== null) {
                    $context['reason'] = $reason;
                }

                IndexLogHelper::appendEntry(
                    $media,
                    IndexLogEntry::info(
                        'metadata.pipeline',
                        'extractor.skip',
                        $message,
                        $context,
                    ),
                );
                $this->telemetry->recordSkip($extractor::class);

                continue;
            }

            $shouldCollectTelemetry = $this->configuration->shouldCollectTelemetry($extractor);
            $startedAt = $shouldCollectTelemetry ? hrtime(true) : null;

            try {
                if ($extractor->supports($filepath, $media) === false) {
                    $this->telemetry->recordSkip($extractor::class);

                    continue;
                }

                $media = $extractor->extract($filepath, $media);
                $this->telemetry->recordSuccess($extractor::class, $this->calculateDuration($startedAt));
            } catch (Throwable $exception) {
                $this->telemetry->recordFailure(
                    $extractor::class,
                    $this->calculateDuration($startedAt),
                    $exception->getMessage(),
                );

                $description = $this->configuration->describeExtractor($extractor);
                $message = sprintf('Extractor %s fehlgeschlagen: %s', $description, $exception->getMessage());
                IndexLogHelper::appendEntry(
                    $media,
                    IndexLogEntry::error(
                        'metadata.pipeline',
                        'extractor.failure',
                        $message,
                        ['extractor' => $description],
                    ),
                );
            }
        }

        return $media;
    }

    private function ensureMimeType(string $filepath, Media $media): void
    {
        if ($media->getMime() !== null) {
            return;
        }

        if (is_file($filepath) === false) {
            IndexLogHelper::appendEntry(
                $media,
                IndexLogEntry::warning(
                    'metadata.mime',
                    'probe',
                    'MIME-Bestimmung übersprungen: Datei nicht gefunden.',
                    ['reason' => 'file_missing'],
                ),
            );

            return;
        }

        $handler = static function (int $severity, string $message): bool {
            $text = $message !== '' ? $message : 'Unbekannter Fehler bei mime_content_type.';

            throw new RuntimeException($text, $severity);
        };

        set_error_handler($handler);

        try {
            $mime = mime_content_type($filepath);
        } catch (Throwable $exception) {
            IndexLogHelper::appendEntry(
                $media,
                IndexLogEntry::error(
                    'metadata.mime',
                    'probe',
                    sprintf('MIME-Bestimmung fehlgeschlagen: %s', $exception->getMessage()),
                    ['error' => $exception->getMessage()],
                ),
            );

            return;
        } finally {
            restore_error_handler();
        }

        if (is_string($mime) && $mime !== '') {
            $media->setMime($mime);
            IndexLogHelper::appendEntry(
                $media,
                IndexLogEntry::info(
                    'metadata.mime',
                    'probe',
                    sprintf('MIME-Bestimmung erfolgreich: %s', $mime),
                    ['mime' => $mime],
                ),
            );

            return;
        }

        IndexLogHelper::appendEntry(
            $media,
            IndexLogEntry::warning(
                'metadata.mime',
                'probe',
                'MIME-Bestimmung fehlgeschlagen: Keine gültige Antwort erhalten.',
                ['error' => 'empty_response'],
            ),
        );
    }

    private function calculateDuration(?int $startedAt): ?float
    {
        if ($startedAt === null) {
            return null;
        }

        $finishedAt = hrtime(true);
        $elapsedNs = $finishedAt - $startedAt;

        if ($elapsedNs <= 0) {
            return 0.0;
        }

        return $elapsedNs / 1_000_000.0;
    }
}
