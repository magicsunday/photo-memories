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

use function array_keys;
use function array_map;
use function array_merge;
use function count;
use function dirname;
use function implode;
use function is_dir;
use function is_string;
use function max;
use function mkdir;
use function sprintf;
use function str_contains;
use function strtolower;
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
        'wipeleft',
        'wiperight',
        'circleopen',
        'circleclose',
        'pixelize',
    ];

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

        $this->runProcess($job, $slides, true);
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     *
     * @return list<string>
     */
    private function buildCommand(SlideshowJob $job, array $slides, bool $useShortestOption): array
    {
        $transitionDuration = $this->resolveTransitionDuration($job->transitionDuration());
        $audioTrack         = $job->audioTrack() ?? $this->audioTrack;

        if (count($slides) === 1) {
            $duration = $this->resolveSlideDuration($slides[0]['duration']);

            return $this->buildSingleImageCommand(
                $slides[0],
                $job->outputPath(),
                $audioTrack,
                $job->title(),
                $job->subtitle(),
                $duration,
                $useShortestOption,
            );
        }

        return $this->buildMultiImageCommand(
            $slides,
            $transitionDuration,
            $job->outputPath(),
            $audioTrack,
            $job->title(),
            $job->subtitle(),
            $this->calculateVideoDuration($slides, $transitionDuration),
            $useShortestOption,
        );
    }

    /**
     * @param array{image:string,mediaId:int|null,duration:float,transition:string|null} $slide
     * @param float $videoDuration
     *
     * @return list<string>
     */
    private function buildSingleImageCommand(
        array $slide,
        string $output,
        ?string $audioTrack,
        ?string $title,
        ?string $subtitle,
        float $videoDuration,
        bool $useShortestOption,
    ): array
    {
        $duration = $this->resolveSlideDuration($slide['duration']);
        $filter = sprintf(
            '[0:v]scale=%1$d:%2$d:force_original_aspect_ratio=decrease,' .
            'pad=%1$d:%2$d:(ow-iw)/2:(oh-ih)/2:black,format=yuv420p,setsar=1,' .
            'trim=duration=%3$.3f,setpts=PTS-STARTPTS[vout]',
            $this->width,
            $this->height,
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

        return $this->appendAudioOptions(
            $command,
            1,
            $output,
            $audioTrack,
            $title,
            $subtitle,
            $videoDuration,
            $useShortestOption,
        );
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     * @param float $videoDuration
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
        float $videoDuration,
        bool $useShortestOption,
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

        $filters = array_map(
            function (int $index) use ($slides, $transitionDuration): string {
                $duration           = $this->resolveSlideDuration($slides[$index]['duration']);
                $durationWithOverlap = max(0.1, $duration + $transitionDuration);

                return sprintf(
                '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=decrease,' .
                'pad=%2$d:%3$d:(ow-iw)/2:(oh-ih)/2:black,format=yuv420p,setsar=1,' .
                'trim=duration=%4$.3f,setpts=PTS-STARTPTS[s%1$d]',
                $index,
                $this->width,
                $this->height,
                $durationWithOverlap
                );
            },
            array_keys($slides),
        );

        $transitionCount = count($this->transitions);
        $current         = '[s0]';
        $offset          = $this->resolveSlideDuration($slides[0]['duration']);

        for ($index = 1; $index < count($slides); ++$index) {
            $transition  = $this->resolveTransition($slides[$index - 1]['transition'], $index - 1, $transitionCount);
            $targetLabel = $index === count($slides) - 1 ? '[vout]' : sprintf('[tmp%d]', $index);
            $xfade = sprintf(
                '%s[s%d]xfade=transition=%s:duration=%0.3f:offset=%0.3f',
                $current,
                $index,
                $transition,
                max(0.1, $transitionDuration),
                $offset,
            );

            $filters[] = sprintf('%s%s', $xfade, $targetLabel);
            $current = $targetLabel;
            $offset += $this->resolveSlideDuration($slides[$index]['duration']);
        }

        $filterComplex = implode(';', $filters);

        $command[] = '-filter_complex';
        $command[] = $filterComplex;
        return $this->appendAudioOptions(
            $command,
            count($slides),
            $output,
            $audioTrack,
            $title,
            $subtitle,
            $videoDuration,
            $useShortestOption,
        );
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     */
    private function calculateVideoDuration(array $slides, float $transitionDuration): float
    {
        $total = 0.0;

        foreach ($slides as $slide) {
            $total += $this->resolveSlideDuration($slide['duration']);
        }

        if (count($slides) > 1) {
            $total += max(0.1, $transitionDuration);
        }

        return max(0.1, $total);
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
        if (is_string($preferred) && $preferred !== '') {
            return $preferred;
        }

        if ($transitionCount === 0) {
            return 'fade';
        }

        $name = $this->transitions[$index % $transitionCount] ?? 'fade';
        if (!is_string($name) || $name === '') {
            return 'fade';
        }

        return $name;
    }

    /**
     * @param list<string> $command
     * @param float $videoDuration
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
        float $videoDuration,
        bool $useShortestOption,
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
            if ($useShortestOption) {
                $command[] = '-shortest';
            } else {
                $command[] = '-to';
                $command[] = sprintf('%0.3f', $videoDuration);
            }
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

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     */
    private function runProcess(SlideshowJob $job, array $slides, bool $useShortestOption): void
    {
        $command = $this->buildCommand($job, $slides, $useShortestOption);

        $process = new Process($command);
        $process->setTimeout(null);

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

            if ($useShortestOption && $this->isShortestOptionUnsupported($message)) {
                $this->runProcess($job, $slides, false);

                return;
            }

            throw new RuntimeException($message, 0, $exception);
        }
    }

    private function isShortestOptionUnsupported(string $message): bool
    {
        $normalized = strtolower($message);

        if (!str_contains($normalized, 'shortest')) {
            return false;
        }

        if (str_contains($normalized, 'not available')) {
            return true;
        }

        if (str_contains($normalized, 'option not found')) {
            return true;
        }

        if (str_contains($normalized, 'unknown option')) {
            return true;
        }

        if (str_contains($normalized, 'unrecognized option')) {
            return true;
        }

        return false;
    }
}
