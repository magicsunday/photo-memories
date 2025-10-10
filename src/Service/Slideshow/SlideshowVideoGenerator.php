<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

use function array_filter;
use function array_merge;
use function ceil;
use function count;
use function dirname;
use function implode;
use function is_dir;
use function is_file;
use function is_string;
use function max;
use function mkdir;
use function rtrim;
use function sprintf;
use function str_replace;
use function trim;

/**
 * FFmpeg based slideshow generator.
 */
final readonly class SlideshowVideoGenerator implements SlideshowVideoGeneratorInterface
{
    /**
     * Default list of transition names used when no custom set is provided.
     */
    private const array DEFAULT_TRANSITIONS = [
        'fade',
        'dissolve',
        'fadeblack',
        'fadewhite',
        'wipeleft',
        'wiperight',
        'wipeup',
        'wipedown',
        'slideleft',
        'slideright',
        'smoothleft',
        'smoothright',
        'circleopen',
        'circleclose',
        'vertopen',
        'vertclose',
        'horzopen',
        'horzclose',
        'radial',
        'pixelize',
    ];

    private const int ZOOMPAN_FPS = 60;

    /**
     * @param list<string> $transitions
     */
    public function __construct(
        private readonly string $ffmpegBinary = 'ffmpeg',
        private readonly float $slideDuration = 3.0,
        private readonly float $transitionDuration = 0.75,
        private readonly int $width = 1280,
        private readonly int $height = 720,
        private readonly array $transitions = self::DEFAULT_TRANSITIONS,
        private readonly ?string $audioTrack = null,
        private readonly ?string $fontFile = null,
        private readonly string $fontFamily = 'DejaVu Sans',
        private readonly float $backgroundBlurSigma = 32.0,
        private readonly bool $kenBurnsEnabled = true,
        private readonly float $zoomStart = 1.05,
        private readonly float $zoomEnd = 1.15,
        private readonly float $panX = 0.0,
        private readonly float $panY = 0.0,
    ) {
    }

    public function generate(SlideshowJob $job): void
    {
        $slides = $job->slides();
        if ($slides === []) {
            throw new RuntimeException('Cannot render slideshow without images.');
        }

        $directory = dirname($job->outputPath());
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create video directory "%s".', $directory));
        }

        $command = $this->buildCommand($job, $slides);

        $process = new Process($command);
        $process->setTimeout(null);
        $process->disableOutput();

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            $message = trim($exception->getProcess()->getErrorOutput());
            if ($message === '') {
                $message = trim($exception->getProcess()->getOutput());
            }

            if ($message === '') {
                $message = $exception->getMessage();
            }

            throw new RuntimeException($message, 0, $exception);
        }
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     *
     * @return list<string>
     */
    private function buildCommand(SlideshowJob $job, array $slides): array
    {
        $transitionDuration = $this->resolveTransitionDuration($job->transitionDuration());
        $audioTrack         = $job->audioTrack() ?? $this->audioTrack;

        if (count($slides) === 1) {
            return $this->buildSingleImageCommand(
                $slides[0],
                $job->outputPath(),
                $audioTrack,
                $job->title(),
                $job->subtitle(),
            );
        }

        return $this->buildMultiImageCommand(
            $slides,
            $transitionDuration,
            $job->outputPath(),
            $audioTrack,
            $job->title(),
            $job->subtitle(),
        );
    }

    /**
     * @param array{image:string,mediaId:int|null,duration:float,transition:string|null} $slide
     *
     * @return list<string>
     */
    private function buildSingleImageCommand(
        array $slide,
        string $output,
        ?string $audioTrack,
        ?string $title,
        ?string $subtitle,
    ): array
    {
        $duration = $this->resolveSlideDuration($slide['duration']);
        $filter   = $this->buildBlurredSlideFilter(0, $duration);

        $filter = $this->appendTextOverlayFilter($filter, $title, $subtitle);
        $filter .= sprintf(
            ',trim=duration=%1$.3f,setpts=PTS-STARTPTS[vout]',
            max(0.1, $duration)
        );

        $command = [
            $this->ffmpegBinary,
            '-y',
            '-loglevel',
            'error',
            '-loop',
            '1',
            '-t',
            sprintf('%0.3f', max(0.1, $duration)),
            '-i',
            $slide['image'],
            '-filter_complex',
            $filter,
        ];

        return $this->appendAudioOptions($command, 1, $output, $audioTrack, $title, $subtitle);
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     *
     * @return list<string>
     */
    private function buildMultiImageCommand(
        array $slides,
        float $transitionDuration,
        string $output,
        ?string $audioTrack,
        ?string $title,
        ?string $subtitle,
    ): array
    {
        $command             = [$this->ffmpegBinary, '-y', '-loglevel', 'error'];
        foreach ($slides as $slide) {
            $duration           = $this->resolveSlideDuration($slide['duration']);
            $durationWithOverlap = max(0.1, $duration + $transitionDuration);
            $command = array_merge($command, [
                '-loop',
                '1',
                '-t',
                sprintf('%0.3f', $durationWithOverlap),
                '-i',
                $slide['image'],
            ]);
        }

        $filters             = [];
        $overlayFilterChain  = $this->buildTextOverlayFilterChain($title, $subtitle);
        $transitionCount     = count($this->transitions);

        foreach ($slides as $index => $slide) {
            $duration            = $this->resolveSlideDuration($slide['duration']);
            $durationWithOverlap = max(0.1, $duration + $transitionDuration);

            $filter = $this->buildBlurredSlideFilter($index, $durationWithOverlap);

            if ($index === 0 && $overlayFilterChain !== '') {
                $filter = sprintf('%s,%s', $filter, $overlayFilterChain);
            }

            $filter .= sprintf(',trim=duration=%1$.3f,setpts=PTS-STARTPTS[s%2$d]', $durationWithOverlap, $index);
            $filters[] = $filter;
        }

        $current = '[s0]';
        $offset  = $this->resolveSlideDuration($slides[0]['duration']);

        for ($index = 1; $index < count($slides); ++$index) {
            $transition  = $this->resolveTransition($slides[$index - 1]['transition'], $index - 1, $transitionCount);
            $targetLabel = $index === count($slides) - 1 ? '[vout]' : sprintf('[tmp%d]', $index);
            $filters[]   = sprintf(
                '%s[s%d]xfade=transition=%s:duration=%0.3f:offset=%0.3f%s',
                $current,
                $index,
                $transition,
                max(0.1, $transitionDuration),
                $offset,
                $targetLabel
            );
            $current = $targetLabel;
            $offset += $this->resolveSlideDuration($slides[$index]['duration']);
        }

        $filterComplex = implode(';', $filters);

        $command[] = '-filter_complex';
        $command[] = $filterComplex;
        return $this->appendAudioOptions($command, count($slides), $output, $audioTrack, $title, $subtitle);
    }

    private function buildBlurredSlideFilter(int $index, float $duration): string
    {
        $background = sprintf(
            '[%1$d:v]split=2[bg%1$d][fg%1$d];[bg%1$d]scale=%2$d:%3$d:force_original_aspect_ratio=increase,crop=%2$d:%3$d',
            $index,
            $this->width,
            $this->height,
        );

        if ($this->backgroundBlurSigma > 0.0) {
            $background .= sprintf(',gblur=sigma=%s', $this->formatFloat($this->backgroundBlurSigma));
        }

        $background .= sprintf('[bg%1$dout];', $index);

        $targetAspectRatio   = $this->formatFloat($this->width / $this->height);
        $durationSecondsValue = max(0.1, $duration);
        $frameCount           = max(2, (int) ceil($durationSecondsValue * self::ZOOMPAN_FPS));
        $maxFrameIndex        = $frameCount - 1;
        $progressExpr         = $this->escapeFilterExpression(sprintf('min(on/%s,1)', $maxFrameIndex));

        if ($this->kenBurnsEnabled) {
            $zoomExpr = $this->escapeFilterExpression(sprintf(
                'if(gte(iw/ih,%1$s),%2$s+(%3$s-%2$s)*%4$s,1)',
                $targetAspectRatio,
                $this->formatFloat($this->zoomStart),
                $this->formatFloat($this->zoomEnd),
                $progressExpr,
            ));

            $panXExpr = $this->escapeFilterExpression(sprintf(
                'if(gte(iw/ih,%1$s),clip((iw-(w/zoom))/2 + %2$s*(iw-(w/zoom))/2*%3$s,0,max(iw-(w/zoom),0)),(iw-w)/2)',
                $targetAspectRatio,
                $this->formatFloat($this->panX),
                $progressExpr,
            ));

            $panYExpr = $this->escapeFilterExpression(sprintf(
                'if(gte(iw/ih,%1$s),clip((ih-(h/zoom))/2 + %2$s*(ih-(h/zoom))/2*%3$s,0,max(ih-(h/zoom),0)),(ih-h)/2)',
                $targetAspectRatio,
                $this->formatFloat($this->panY),
                $progressExpr,
            ));
        } else {
            $zoomExpr = '1';
            $panXExpr = $this->escapeFilterExpression(sprintf('if(gte(iw/ih,%1$s),(iw-w)/2,(iw-w)/2)', $targetAspectRatio));
            $panYExpr = $this->escapeFilterExpression(sprintf('if(gte(iw/ih,%1$s),(ih-h)/2,(ih-h)/2)', $targetAspectRatio));
        }

        $zoomSizeExpr = sprintf('%1$dx%2$d', $this->width, $this->height);
        $cropWidthExpr = $this->escapeFilterExpression(sprintf('if(gte(iw/ih,%1$s),%2$d,iw)', $targetAspectRatio, $this->width));
        $cropHeightExpr = $this->escapeFilterExpression(sprintf('if(gte(iw/ih,%1$s),%2$d,ih)', $targetAspectRatio, $this->height));

        $zoompanFps = $this->formatFloat((float) self::ZOOMPAN_FPS);

        $foreground = sprintf(
            "[fg%1\$d]scale=%2\$d:%3\$d:force_original_aspect_ratio=increase," .
            "zoompan=z=%4\$s:x=%5\$s:y=%6\$s:d=1:s=%7\$s:fps=%8\$s," .
            "crop=%9\$s:%10\$s:(in_w-out_w)/2:(in_h-out_h)/2[fg%1\$dout];",
            $index,
            $this->width,
            $this->height,
            $zoomExpr,
            $panXExpr,
            $panYExpr,
            $zoomSizeExpr,
            $zoompanFps,
            $cropWidthExpr,
            $cropHeightExpr,
        );

        return sprintf(
            '%1$s%2$s[bg%3$dout][fg%3$dout]overlay=(main_w-overlay_w)/2:(main_h-overlay_h)/2,format=yuv420p,setsar=1',
            $background,
            $foreground,
            $index,
        );
    }

    private function resolveSlideDuration(float $duration): float
    {
        $value = $duration > 0.0 ? $duration : $this->slideDuration;

        return max(0.1, $value);
    }

    private function resolveTransitionDuration(?float $duration): float
    {
        if ($duration !== null && $duration > 0.0) {
            return $duration;
        }

        return max(0.1, $this->transitionDuration);
    }

    private function resolveTransition(?string $preferred, int $index, int $transitionCount): string
    {
        if (is_string($preferred)) {
            $trimmed = trim($preferred);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        if ($transitionCount === 0) {
            return 'fade';
        }

        $name = $this->transitions[$index % $transitionCount] ?? 'fade';
        if (!is_string($name)) {
            return 'fade';
        }

        $trimmedName = trim($name);
        if ($trimmedName === '') {
            return 'fade';
        }

        return $trimmedName;
    }

    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    private function appendAudioOptions(
        array $command,
        int $videoInputs,
        string $output,
        ?string $audioTrack,
        ?string $title,
        ?string $subtitle,
    ): array
    {
        if (is_string($audioTrack) && $audioTrack !== '') {
            $command[] = '-i';
            $command[] = $audioTrack;
        }

        $command[] = '-map';
        $command[] = '[vout]';
        $command[] = '-movflags';
        $command[] = '+faststart';
        $command[] = '-pix_fmt';
        $command[] = 'yuv420p';

        if (is_string($title) && $title !== '') {
            $command[] = '-metadata';
            $command[] = sprintf('title=%s', $title);
        }

        if (is_string($subtitle) && $subtitle !== '') {
            $command[] = '-metadata';
            $command[] = sprintf('subtitle=%s', $subtitle);
        }

        if (is_string($audioTrack) && $audioTrack !== '') {
            $command[] = '-map';
            $command[] = sprintf('%d:a:0', $videoInputs);
            $command[] = '-shortest';
            $command[] = '-c:a';
            $command[] = 'aac';
            $command[] = '-b:a';
            $command[] = '192k';
        } else {
            $command[] = '-an';
        }

        $command[] = $output;

        return $command;
    }

    private function appendTextOverlayFilter(string $filter, ?string $title, ?string $subtitle): string
    {
        $overlay = $this->buildTextOverlayFilterChain($title, $subtitle);
        if ($overlay === '') {
            return $filter;
        }

        return sprintf('%s,%s', $filter, $overlay);
    }

    private function hasTextOverlay(?string $title, ?string $subtitle): bool
    {
        return (is_string($title) && $title !== '') || (is_string($subtitle) && $subtitle !== '');
    }

    private function buildTextOverlayFilterChain(?string $title, ?string $subtitle): string
    {
        if (!$this->hasTextOverlay($title, $subtitle)) {
            return '';
        }

        $filters = [];

        $subtitleText = $this->normaliseDrawText($subtitle);
        $titleText    = $this->normaliseDrawText($title);

        if ($subtitleText !== null) {
            $filters[] = $this->buildDrawTextFilter($subtitleText, 48, 'w*0.05', 'h-80');
        }

        if ($titleText !== null) {
            $titleY    = $subtitleText !== null ? 'h-80-line_h-30' : 'h-80';
            $filters[] = $this->buildDrawTextFilter($titleText, 64, 'w*0.05', $titleY);
        }

        return implode(',', array_filter($filters));
    }

    private function normaliseDrawText(?string $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return trim($this->escapeDrawTextValue($value));
    }

    private function buildDrawTextFilter(string $text, int $fontSize, string $positionX, string $positionY): string
    {
        $fontDirective = $this->resolveFontDirective();
        $fontSegment   = $fontDirective !== '' ? sprintf('%s:', $fontDirective) : '';

        return sprintf(
            "drawtext=text='%s':%sfontcolor=white:fontsize=%d:shadowcolor=0x000000AA:shadowx=0:shadowy=6:" .
            'borderw=2:bordercolor=0x00000066:x=%s:y=%s',
            $text,
            $fontSegment,
            $fontSize,
            $positionX,
            $positionY
        );
    }

    private function resolveFontDirective(): string
    {
        if (is_string($this->fontFile)) {
            $fontFile = trim($this->fontFile);
            if ($fontFile !== '' && is_file($fontFile)) {
                return sprintf("fontfile='%s'", $this->escapeDrawTextValue($fontFile));
            }
        }

        $fontFamily = trim($this->fontFamily);
        if ($fontFamily !== '') {
            return sprintf("font='%s'", $this->escapeDrawTextValue($fontFamily));
        }

        return '';
    }

    private function escapeDrawTextValue(string $value): string
    {
        return str_replace(
            ['\\', ':', '%', "'", ',', '[', ']'],
            ['\\\\', '\\:', '\\%', "\\'", '\\,', '\\[', '\\]'],
            $value
        );
    }

    private function escapeFilterExpression(string $expression): string
    {
        $length  = strlen($expression);
        $escaped = '';

        for ($index = 0; $index < $length; ++$index) {
            $character = $expression[$index];
            if ($character === ',') {
                $previous = $index > 0 ? $expression[$index - 1] : null;
                if ($previous !== '\\') {
                    $escaped .= '\\';
                }
            }

            $escaped .= $character;
        }

        return $escaped;
    }

    private function formatFloat(float $value): string
    {
        $formatted = sprintf('%0.3F', $value);

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
