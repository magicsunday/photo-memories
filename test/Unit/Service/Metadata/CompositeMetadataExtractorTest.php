<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Service\Metadata\CompositeMetadataExtractor;
use MagicSunday\Memories\Service\Metadata\MetadataExtractorPipelineConfiguration;
use MagicSunday\Memories\Service\Metadata\MetadataExtractorTelemetry;
use MagicSunday\Memories\Service\Metadata\SingleMetadataExtractorInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function array_key_last;
use function chmod;
use function file_put_contents;
use function is_file;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class CompositeMetadataExtractorTest extends TestCase
{
    #[Test]
    public function skipsMimeGuessWhenFileDoesNotExist(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'missing_media_');
        if ($tmp === false) {
            self::fail('Unable to allocate temporary filename.');
        }

        if (is_file($tmp) && @unlink($tmp) === false) {
            self::fail('Unable to remove temporary placeholder.');
        }

        $media = $this->makeMedia(
            id: 101,
            path: $tmp,
            checksum: str_repeat('0', 64),
            size: 512,
        );

        $composite = $this->makeComposite([]);

        $composite->extract($tmp, $media);

        self::assertNull($media->getMime());
        $entries = $this->decodeIndexLog($media->getIndexLog());
        self::assertCount(1, $entries);
        self::assertSame('metadata.mime', $entries[0]['component']);
        self::assertSame('MIME-Bestimmung übersprungen: Datei nicht gefunden.', $entries[0]['message']);
        self::assertSame('file_missing', $entries[0]['context']['reason'] ?? null);
    }

    #[Test]
    public function guessMimeSetsMimeAndLogsSuccess(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'media_mime_success_');
        if ($tmp === false) {
            self::fail('Unable to allocate temporary filename.');
        }

        $bytesWritten = file_put_contents($tmp, 'plain-text');
        if ($bytesWritten === false) {
            self::fail('Unable to populate temporary file.');
        }

        $media = $this->makeMedia(
            id: 102,
            path: $tmp,
            checksum: str_repeat('1', 64),
            size: 512,
        );

        $composite = $this->makeComposite([]);

        $composite->extract($tmp, $media);

        self::assertSame('text/plain', $media->getMime());
        $entries = $this->decodeIndexLog($media->getIndexLog());
        self::assertCount(1, $entries);
        self::assertSame('metadata.mime', $entries[0]['component']);
        self::assertSame('MIME-Bestimmung erfolgreich: text/plain', $entries[0]['message']);
        self::assertSame('text/plain', $entries[0]['context']['mime'] ?? null);

        if (is_file($tmp) && unlink($tmp) === false) {
            self::fail('Unable to clean up temporary file.');
        }
    }

    #[Test]
    public function guessMimeLogsFailureWhenFileIsUnreadable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'media_mime_failure_');
        if ($tmp === false) {
            self::fail('Unable to allocate temporary filename.');
        }

        $bytesWritten = file_put_contents($tmp, 'locked');
        if ($bytesWritten === false) {
            self::fail('Unable to populate temporary file.');
        }

        if (chmod($tmp, 0) === false) {
            if (is_file($tmp) && unlink($tmp) === false) {
                self::fail('Unable to clean up temporary file after chmod failure.');
            }

            self::markTestSkipped('Filesystem permissions cannot be adjusted in this environment.');
        }

        $media = $this->makeMedia(
            id: 103,
            path: $tmp,
            checksum: str_repeat('2', 64),
            size: 512,
        );

        $composite = $this->makeComposite([]);

        try {
            $composite->extract($tmp, $media);
        } finally {
            if (chmod($tmp, 0o644) === false) {
                self::fail('Unable to restore permissions for cleanup.');
            }

            if (is_file($tmp) && unlink($tmp) === false) {
                self::fail('Unable to clean up temporary file.');
}

    #[Test]
    public function disabledExtractorIsLoggedAndSkipped(): void
    {
        $extractor = new class() implements SingleMetadataExtractorInterface {
            public bool $supportsCalled = false;

            public function supports(string $filepath, $media): bool
            {
                $this->supportsCalled = true;

                return true;
            }

            public function extract(string $filepath, $media)
            {
                return $media;
            }
        };

        $configuration = new MetadataExtractorPipelineConfiguration([
            $extractor::class => [
                'enabled' => false,
                'reason' => 'Testabschaltung',
            ],
        ], false);

        $telemetry = new MetadataExtractorTelemetry();
        $composite = $this->makeComposite([$extractor], $configuration, $telemetry);

        $media = $this->makeMedia(
            id: 301,
            path: '/tmp/disabled',
        );

        $composite->extract('/tmp/disabled', $media);

        self::assertFalse($extractor->supportsCalled);
        $entries = $this->decodeIndexLog($media->getIndexLog());
        self::assertNotSame([], $entries);
        $last = $entries[array_key_last($entries)];
        self::assertSame('metadata.pipeline', $last['component']);
        self::assertSame('extractor.skip', $last['event']);
        self::assertStringContainsString('Extractor CompositeMetadataExtractorTest@anonymous', (string) $last['message']);
        self::assertSame('Testabschaltung', $last['context']['reason'] ?? null);

        $summary = $telemetry->get($extractor::class);
        self::assertNotNull($summary);
        self::assertSame(0, $summary->getRuns());
        self::assertSame(1, $summary->getSkips());
    }

    #[Test]
    public function extractorFailuresAreLoggedAndMeasured(): void
    {
        $extractor = new class() implements SingleMetadataExtractorInterface {
            public function supports(string $filepath, $media): bool
            {
                return true;
            }

            public function extract(string $filepath, $media)
            {
                throw new \RuntimeException('broken for test');
            }
        };

        $configuration = new MetadataExtractorPipelineConfiguration([], true);
        $telemetry = new MetadataExtractorTelemetry();
        $composite = $this->makeComposite([$extractor], $configuration, $telemetry);

        $media = $this->makeMedia(
            id: 302,
            path: '/tmp/failure',
        );

        $composite->extract('/tmp/failure', $media);

        $entries = $this->decodeIndexLog($media->getIndexLog());
        self::assertNotSame([], $entries);
        $last = $entries[array_key_last($entries)];
        self::assertSame('metadata.pipeline', $last['component']);
        self::assertSame('extractor.failure', $last['event']);
        self::assertStringContainsString('Extractor CompositeMetadataExtractorTest@anonymous', (string) $last['message']);
        self::assertStringContainsString('fehlgeschlagen: broken for test', (string) $last['message']);

        $summary = $telemetry->get($extractor::class);
        self::assertNotNull($summary);
        self::assertSame(1, $summary->getRuns());
        self::assertSame(1, $summary->getFailures());
        self::assertSame('broken for test', $summary->getLastErrorMessage());
    }

    #[Test]
    public function telemetryCapturesSuccessfulRunsWhenEnabled(): void
    {
        $extractor = new class() implements SingleMetadataExtractorInterface {
            public int $calls = 0;

            public function supports(string $filepath, $media): bool
            {
                $this->calls++;

                return true;
            }

            public function extract(string $filepath, $media)
            {
                return $media;
            }
        };

        $configuration = new MetadataExtractorPipelineConfiguration([], true);
        $telemetry = new MetadataExtractorTelemetry();
        $composite = $this->makeComposite([$extractor], $configuration, $telemetry);

        $media = $this->makeMedia(
            id: 303,
            path: '/tmp/success',
        );

        $composite->extract('/tmp/success', $media);

        self::assertSame(1, $extractor->calls);

        $summary = $telemetry->get($extractor::class);
        self::assertNotNull($summary);
        self::assertSame(1, $summary->getRuns());
        self::assertSame(0, $summary->getFailures());
        self::assertGreaterThanOrEqual(0.0, $summary->getTotalDurationMs());
    }

    /**
     * @param list<SingleMetadataExtractorInterface> $extractors
     */
    private function makeComposite(
        array $extractors,
        ?MetadataExtractorPipelineConfiguration $configuration = null,
        ?MetadataExtractorTelemetry $telemetry = null,
    ): CompositeMetadataExtractor {
        return new CompositeMetadataExtractor(
            $extractors,
            $configuration ?? new MetadataExtractorPipelineConfiguration([], false),
            $telemetry ?? new MetadataExtractorTelemetry(),
        );
    }
}
