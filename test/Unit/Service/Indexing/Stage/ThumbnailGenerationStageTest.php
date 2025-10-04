<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Indexing\Stage;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionContext;
use MagicSunday\Memories\Service\Indexing\Stage\ThumbnailGenerationStage;
use MagicSunday\Memories\Service\Thumbnail\ThumbnailServiceInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

final class ThumbnailGenerationStageTest extends TestCase
{
    #[Test]
    public function processAppendsIndexLogWhenGenerationFails(): void
    {
        $media  = new Media('/tmp/file.jpg', 'checksum', 1);
        $output = new BufferedOutput();

        $context = MediaIngestionContext::create('/tmp/file.jpg', false, false, true, false, $output)
            ->withMedia($media);

        $thumbnailService = $this->createMock(ThumbnailServiceInterface::class);
        $thumbnailService->expects(self::once())
            ->method('generateAll')
            ->willThrowException(new RuntimeException('Generation failed'));

        $stage = new ThumbnailGenerationStage($thumbnailService);

        $stage->process($context);

        self::assertSame('Thumbnail generation failed for /tmp/file.jpg: Generation failed', $media->getIndexLog());
    }
}

