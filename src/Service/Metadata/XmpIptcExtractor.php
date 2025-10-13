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
use MagicSunday\Memories\Service\Indexing\Contract\MediaIngestionTelemetryInterface;

use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function getimagesize;
use function iptcparse;
use function is_array;
use function is_file;
use function is_string;
use function preg_match_all;
use function str_starts_with;
use function strip_tags;
use function trim;

/**
 * Reads IPTC APP13 + XMP keywords/persons.
 */
final class XmpIptcExtractor implements SingleMetadataExtractorInterface
{
    public function __construct(
        private ?MediaIngestionTelemetryInterface $telemetry = null,
    ) {
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();

        return $mime !== null && str_starts_with($mime, 'image/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        if (!is_file($filepath)) {
            return $media;
        }

        $keywords = $media->getKeywords() ?? [];
        $persons  = $media->getPersons() ?? [];

        $info = [];
        @getimagesize($filepath, $info);
        if (isset($info['APP13'])) {
            $iptc = @iptcparse($info['APP13']);
            if (is_array($iptc)) {
                $kw = $this->fromIptcStrings($iptc['2#025'] ?? null);
                if ($kw !== []) {
                    $keywords = array_values(array_unique(array_merge($keywords, $kw)));
                }

                $pp = $this->fromIptcStrings($iptc['2#122'] ?? null);
                if ($pp !== []) {
                    $persons = array_values(array_unique(array_merge($persons, $pp)));
                }
            }
        }

        $sidecar = $filepath . '.xmp';
        if (is_file($sidecar)) {
            $this->parseXmpXml($filepath, (string) @file_get_contents($sidecar), $keywords, $persons);
        } else {
            $blob = @file_get_contents($filepath, false, null, 0, 256 * 1024);
            if (is_string($blob) && $blob !== '') {
                $this->parseXmpXml($filepath, $blob, $keywords, $persons);
            }
        }

        $media->setKeywords($keywords !== [] ? $keywords : null);

        $personCount = count($persons);
        $media->setPersons($persons !== [] ? $persons : null);
        $media->setHasFaces($personCount > 0);
        $media->setFacesCount($personCount);

        return $media;
    }

    /** @param array|bool|float|int|string|null $v @return list<string> */
    private function fromIptcStrings(array|bool|float|int|string|null $v): array
    {
        if (is_array($v)) {
            /** @var list<string> $out */
            $out = [];
            foreach ($v as $s) {
                if (is_string($s) && $s !== '') {
                    $out[] = $s;
                }
            }

            return $out;
        }

        return [];
    }

    /** @param list<string> $keywords @param list<string> $persons */
    private function parseXmpXml(string $filepath, ?string $xml, array &$keywords, array &$persons): void
    {
        if (!is_string($xml) || $xml === '') {
            return;
        }

        $kwMatches = [];
        if (preg_match_all('~<rdf:li[^>]*>(.*?)</rdf:li>~si', $xml, $kwMatches)) {
            foreach ($kwMatches[1] as $w) {
                $w = trim(strip_tags($w));
                if ($w !== '') {
                    $keywords[] = $w;
                }
            }
        }

        $pMatches = [];
        if (preg_match_all('~<mwg-rs:Name[^>]*>(.*?)</mwg-rs:Name>~si', $xml, $pMatches)) {
            foreach ($pMatches[1] as $p) {
                $p = trim(strip_tags($p));
                if ($p !== '') {
                    $persons[] = $p;
                }
            }
        }

        if (count($keywords) > 1) {
            $keywords = array_values(array_unique($keywords));
        }

        if (count($persons) > 1) {
            $persons = array_values(array_unique($persons));
        }

        if ($this->telemetry !== null && $this->containsTimezoneInformation($xml)) {
            $this->telemetry->recordXmpTimezoneHit($filepath);
        }
    }

    private function containsTimezoneInformation(string $xml): bool
    {
        if ($xml === '') {
            return false;
        }

        $matches = [];
        if (!preg_match_all(
            '~<(?:xmp:CreateDate|photoshop:DateCreated|exif:DateTimeOriginal)>([^<]+)</[^>]+>~i',
            $xml,
            $matches,
        )) {
            return false;
        }

        foreach ($matches[1] as $value) {
            $candidate = trim(strip_tags($value));
            if ($candidate === '') {
                continue;
            }

            if (preg_match('/(?:[+-][0-9]{2}:[0-9]{2}|Z)$/', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }
}
