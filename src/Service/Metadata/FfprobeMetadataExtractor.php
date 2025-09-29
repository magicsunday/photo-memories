<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use MagicSunday\Memories\Entity\Media;

final readonly class FfprobeMetadataExtractor implements SingleMetadataExtractorInterface
{
    public function __construct(
        private string $ffprobePath = 'ffprobe',
        private float $slowMoFpsThreshold = 100.0
    ) {
    }

    public function supports(string $filepath, Media $media): bool
    {
        $mime = $media->getMime();
        return $mime !== null && \str_starts_with($mime, 'video/');
    }

    public function extract(string $filepath, Media $media): Media
    {
        $media->setIsVideo(true);
        if (!\is_file($filepath)) {
            return $media;
        }

        $cmd = \sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=codec_name,avg_frame_rate -show_entries format=duration -of default=nw=1:nk=1 %s',
            \escapeshellcmd($this->ffprobePath),
            \escapeshellarg($filepath)
        );
        $out = @\shell_exec($cmd);
        if (!\is_string($out) || $out === '') {
            return $media;
        }

        $lines = \array_map('trim', \explode("\n", $out));
        if (\count($lines) >= 3) {
            $codec = $lines[0] !== '' ? $lines[0] : null;
            $fps   = $this->parseFps($lines[1] ?? null);
            $dur   = $this->parseFloat($lines[2] ?? null);

            if ($codec !== null) { $media->setVideoCodec($codec); }

            if ($fps !== null)   { $media->setVideoFps($fps); }

            if ($dur !== null)   { $media->setVideoDurationS($dur); }

            if ($fps !== null) {
                $media->setIsSlowMo($fps >= $this->slowMoFpsThreshold);
            }
        }

        return $media;
    }

    private function parseFps(?string $v): ?float
    {
        if ($v === null || $v === '0/0' || $v === '') {
            return null;
        }

        if (\str_contains($v, '/')) {
            [$a, $b] = \array_pad(\explode('/', $v, 2), 2, '1');
            $bn = (float) $b;
            return $bn !== 0.0 ? (float) $a / $bn : null;
        }

        return (float) $v;
    }

    private function parseFloat(?string $v): ?float
    {
        if ($v === null || $v === '') { return null; }

        return (float) $v;
    }
}
