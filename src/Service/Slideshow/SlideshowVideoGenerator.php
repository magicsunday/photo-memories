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
            return $this->buildSingleImageCommand($slides[0], $job, $job->outputPath(), $audioTrack);
        }

        return $this->buildMultiImageCommand($slides, $job, $transitionDuration, $job->outputPath(), $audioTrack);
    }

    /**
     * @param array{image:string,mediaId:int|null,duration:float,transition:string|null} $slide
     *
     * @return list<string>
     */
    private function buildSingleImageCommand(array $slide, SlideshowJob $job, string $output, ?string $audioTrack): array
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

        $command = $this->appendMetadataOptions($command, $job);

        return $this->appendAudioOptions($command, 1, $output, $audioTrack);
    }

    /**
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     *
     * @return list<string>
     */
    private function buildMultiImageCommand(
        array $slides,
        SlideshowJob $job,
        float $transitionDuration,
        string $output,
        ?string $audioTrack,
    ): array {
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
            $filters[]   = sprintf(
                '%s[s%d]xfade=transition=%s:duration=%0.3f:offset=%0.3f:shortest=1%s',
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
        $command   = $this->appendMetadataOptions($command, $job);

        return $this->appendAudioOptions($command, count($slides), $output, $audioTrack);
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
     *
     * @return list<string>
     */
    private function appendMetadataOptions(array $command, SlideshowJob $job): array
    {
        $metadata = [];

        $title = $this->normaliseMetadataValue($job->title());
        if ($title !== null) {
            $metadata['title'] = $title;
        }

        $subtitle = $this->normaliseMetadataValue($job->subtitle());
        if ($subtitle !== null) {
            $metadata['comment']      = $subtitle;
            $metadata['description'] = $subtitle;
        }

        foreach ($metadata as $key => $value) {
            $command[] = '-metadata';
            $command[] = sprintf('%s=%s', $key, $value);
        }

        return $command;
    }

    private function normaliseMetadataValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    private function appendAudioOptions(array $command, int $videoInputs, string $output, ?string $audioTrack): array
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
}
