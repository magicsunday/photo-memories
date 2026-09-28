<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\AppleHeuristicsExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sha1;
use function sort;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class AppleHeuristicsExtractorTest extends TestCase
{
    #[Test]
    public function buildsChecksumForUppercaseJpgMovPair(): void
    {
        [$directory, $filenames] = $this->createFixture([
            'IMG_1001.JPG' => 'photo',
            'IMG_1001.MOV' => 'video',
        ]);

        try {
            $photo = $directory . '/IMG_1001.JPG';

            $media = $this->makeMedia(
                id: 1,
                path: $photo,
                configure: static function (Media $item): void {
                    $item->setMime('image/jpeg');
                },
            );

            $extractor = new AppleHeuristicsExtractor();

            $result = $extractor->extract($media->getPath(), $media);

            $expected = $filenames;
            sort($expected, SORT_STRING);

            self::assertSame(sha1(implode('|', $expected)), $result->getLivePairChecksum());
        } finally {
            $this->removeFixture($directory, $filenames);
        }
    }

    #[Test]
    public function buildsChecksumForUppercaseHeicMp4Pair(): void
    {
        [$directory, $filenames] = $this->createFixture([
            'IMG_2001.HEIC' => 'photo',
            'IMG_2001.MP4'  => 'video',
        ]);

        try {
            $photo = $directory . '/IMG_2001.HEIC';

            $media = $this->makeMedia(
                id: 2,
                path: $photo,
                configure: static function (Media $item): void {
                    $item->setMime('image/heic');
                },
            );

            $extractor = new AppleHeuristicsExtractor();

            $result = $extractor->extract($media->getPath(), $media);

            $expected = $filenames;
            sort($expected, SORT_STRING);

            self::assertSame(sha1(implode('|', $expected)), $result->getLivePairChecksum());
        } finally {
            $this->removeFixture($directory, $filenames);
        }
    }

    /**
     * @param array<string, string> $files
     *
     * @return array{string, list<string>}
     */
    private function createFixture(array $files): array
    {
        $directory = sprintf('%s/pm_apple_%s', sys_get_temp_dir(), uniqid('', true));
        self::assertTrue(mkdir($directory));

        /** @var list<string> $filenames */
        $filenames = [];

        foreach ($files as $name => $contents) {
            $path = $directory . '/' . $name;
            self::assertNotFalse(file_put_contents($path, $contents));
            $filenames[] = $name;
        }

        return [$directory, $filenames];
    }

    /**
     * @param list<string> $filenames
     */
    private function removeFixture(string $directory, array $filenames): void
    {
        foreach ($filenames as $name) {
            $path = $directory . '/' . $name;
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
