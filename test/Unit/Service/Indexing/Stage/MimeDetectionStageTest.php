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
    private ?string $tempFile = null;

    protected function tearDown(): void
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        $this->tempFile = null;

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

    private function createTempFile(string $extension, string $content): string
    {
        $path = sys_get_temp_dir() . '/memories-stage-' . uniqid('', true) . '.' . $extension;
        file_put_contents($path, $content);
        $this->tempFile = $path;

        return $path;
    }
}
