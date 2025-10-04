<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing\Stage;

use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\MimeDetectionStage;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class MimeDetectionStageTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    #[Test]
    public function processMarksContextSkippedWhenStrictValidationFails(): void
    {
        $filepath = $this->createTempFile('jpg', 'plain-text-content');
        $stage    = new MimeDetectionStage(['jpg'], []);
        $context  = MediaIngestionContext::create(
            $filepath,
            false,
            false,
            false,
            true,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        );

        $result = $stage->process($context);

        self::assertNotNull($result->getDetectedMime());
        self::assertTrue($result->isSkipped());
    }

    #[Test]
    public function processSetsFormatFlagsFromExtensions(): void
    {
        $stage = new MimeDetectionStage();

        $heicPath = $this->createTempFile('heic', 'heic-fixture');
        $heicContext = MediaIngestionContext::create(
            $heicPath,
            false,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        );

        $heicResult = $stage->process($heicContext);

        self::assertTrue($heicResult->isDetectedHeic());
        self::assertFalse($heicResult->isDetectedRaw());
        self::assertFalse($heicResult->isDetectedHevc());

        $rawPath = $this->createTempFile('dng', 'raw-fixture');
        $rawContext = MediaIngestionContext::create(
            $rawPath,
            false,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        );

        $rawResult = $stage->process($rawContext);

        self::assertTrue($rawResult->isDetectedRaw());
        self::assertFalse($rawResult->isDetectedHeic());

        $hevcPath = $this->createTempFile('hevc', 'video-fixture');
        $hevcContext = MediaIngestionContext::create(
            $hevcPath,
            false,
            false,
            false,
            false,
            new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE)
        );

        $hevcResult = $stage->process($hevcContext);

        self::assertTrue($hevcResult->isDetectedHevc());
        self::assertFalse($hevcResult->isDetectedRaw());
        self::assertFalse($hevcResult->isDetectedHeic());
    }

    private function createTempFile(string $extension, string $content): string
    {
        $path = sys_get_temp_dir() . '/memories-stage-' . uniqid('', true) . '.' . $extension;
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
