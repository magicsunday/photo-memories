<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Support;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Support\IndexLogHelper;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class IndexLogHelperTest extends TestCase
{
    #[Test]
    public function appendPreservesExplicitBlankLines(): void
    {
        $media = new Media('/pfad/zur/datei.jpg', 'checksum', 1024);
        $media->setIndexLog("Erster Eintrag\n\n");

        IndexLogHelper::append($media, 'Zweiter Eintrag');

        self::assertSame("Erster Eintrag\n\nZweiter Eintrag", $media->getIndexLog());
    }

    #[Test]
    public function appendRespectsExistingTrailingNewline(): void
    {
        $media = new Media('/pfad/zur/datei.jpg', 'checksum', 1024);
        $media->setIndexLog("Erster Eintrag\r\n");

        IndexLogHelper::append($media, 'Zweiter Eintrag');

        self::assertSame("Erster Eintrag\r\nZweiter Eintrag", $media->getIndexLog());
    }
}
