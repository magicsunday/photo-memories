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
use MagicSunday\Memories\Service\Metadata\XmpIptcExtractor;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class XmpIptcExtractorTest extends TestCase
{
    #[Test]
    public function populatesFaceFlagsFromXmpSidecar(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mem-xmp-');
        self::assertIsString($path);

        /** @var string $path */
        file_put_contents($path, 'fake-image');
        file_put_contents($path . '.xmp', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:mwg-rs="http://www.metadataworkinggroup.com/schemas/regions/">
  <rdf:RDF>
    <rdf:Description>
      <mwg-rs:Regions>
        <rdf:Bag>
          <rdf:li>
            <mwg-rs:Name>Alice</mwg-rs:Name>
          </rdf:li>
          <rdf:li>
            <mwg-rs:Name>Bob</mwg-rs:Name>
          </rdf:li>
        </rdf:Bag>
      </mwg-rs:Regions>
      <dc:subject xmlns:dc="http://purl.org/dc/elements/1.1/">
        <rdf:Bag>
          <rdf:li>holiday</rdf:li>
        </rdf:Bag>
      </dc:subject>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML
        );

        try {
            $media = $this->makeMedia(200, $path, configure: static function (Media $media): void {
                $media->setMime('image/jpeg');
            });

            $extractor = new XmpIptcExtractor();
            $extractor->extract($path, $media);

            $keywords = $media->getKeywords();
            self::assertIsArray($keywords);
            self::assertContains('holiday', $keywords);
            self::assertSame(['Alice', 'Bob'], $media->getPersons());
            self::assertTrue($media->hasFaces());
            self::assertSame(2, $media->getFacesCount());
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }

            $sidecar = $path . '.xmp';
            if (file_exists($sidecar)) {
                unlink($sidecar);
            }
        }
    }

    #[Test]
    public function resetsFaceFlagsWhenNoPersonsPresent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mem-xmp-');
        self::assertIsString($path);

        /** @var string $path */
        file_put_contents($path, 'fake-image');
        file_put_contents($path . '.xmp', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/" xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <rdf:RDF>
    <rdf:Description>
      <dc:subject xmlns:dc="http://purl.org/dc/elements/1.1/">
        <rdf:Bag>
          <rdf:li>landscape</rdf:li>
        </rdf:Bag>
      </dc:subject>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
XML
        );

        try {
            $media = $this->makeMedia(201, $path, configure: static function (Media $media): void {
                $media->setMime('image/jpeg');
                $media->setHasFaces(true);
                $media->setFacesCount(3);
            });

            $extractor = new XmpIptcExtractor();
            $extractor->extract($path, $media);

            $keywords = $media->getKeywords();
            self::assertIsArray($keywords);
            self::assertContains('landscape', $keywords);
            self::assertNull($media->getPersons());
            self::assertFalse($media->hasFaces());
            self::assertSame(0, $media->getFacesCount());
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }

            $sidecar = $path . '.xmp';
            if (file_exists($sidecar)) {
                unlink($sidecar);
            }
        }
    }
}
