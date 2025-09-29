<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;

/**
 * Reads IPTC APP13 + XMP keywords/persons.
 */
final class XmpIptcExtractor implements SingleMetadataExtractorInterface
{
    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();
        return $mime !== null && \str_starts_with($mime, 'image/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        if (!\is_file($filepath)) {
            return $media;
        }

        $keywords = $media->getKeywords() ?? [];
        $persons  = $media->getPersons() ?? [];

        $info = [];
        @\getimagesize($filepath, $info);
        if (isset($info['APP13'])) {
            $iptc = @\iptcparse($info['APP13']);
            if (\is_array($iptc)) {
                $kw = $this->fromIptcStrings($iptc['2#025'] ?? null);
                if ($kw !== []) {
                    $keywords = \array_values(\array_unique(\array_merge($keywords, $kw)));
                }

                $pp = $this->fromIptcStrings($iptc['2#122'] ?? null);
                if ($pp !== []) {
                    $persons = \array_values(\array_unique(\array_merge($persons, $pp)));
                }
            }
        }

        $sidecar = $filepath . '.xmp';
        if (\is_file($sidecar)) {
            $this->parseXmpXml((string) @\file_get_contents($sidecar), $keywords, $persons);
        } else {
            $blob = @\file_get_contents($filepath, false, null, 0, 256 * 1024);
            if (\is_string($blob) && $blob !== '') {
                $this->parseXmpXml($blob, $keywords, $persons);
            }
        }

        $media->setKeywords($keywords !== [] ? $keywords : null);
        $media->setPersons($persons !== [] ? $persons : null);
        return $media;
    }

    /** @param mixed $v @return list<string> */
    private function fromIptcStrings($v): array
    {
        if (\is_array($v)) {
            /** @var list<string> $out */
            $out = [];
            foreach ($v as $s) {
                if (\is_string($s) && $s !== '') {
                    $out[] = $s;
                }
            }

            return $out;
        }

        return [];
    }

    /** @param list<string> $keywords @param list<string> $persons */
    private function parseXmpXml(?string $xml, array &$keywords, array &$persons): void
    {
        if (!\is_string($xml) || $xml === '') {
            return;
        }

        $kwMatches = [];
        if (\preg_match_all('~<rdf:li[^>]*>(.*?)</rdf:li>~si', $xml, $kwMatches)) {
            foreach ($kwMatches[1] as $w) {
                $w = \trim(\strip_tags($w));
                if ($w !== '') { $keywords[] = $w; }
            }
        }

        $pMatches = [];
        if (\preg_match_all('~<mwg-rs:Name[^>]*>(.*?)</mwg-rs:Name>~si', $xml, $pMatches)) {
            foreach ($pMatches[1] as $p) {
                $p = \trim(\strip_tags($p));
                if ($p !== '') { $persons[] = $p; }
            }
        }

        if (\count($keywords) > 1) {
            $keywords = \array_values(\array_unique($keywords));
        }

        if (\count($persons) > 1) {
            $persons  = \array_values(\array_unique($persons));
        }
    }
}
