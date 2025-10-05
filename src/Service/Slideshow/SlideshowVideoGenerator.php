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
    ) {
    }

    public function generate(SlideshowJob $job): void
    {
        $images = $job->images();
        $count  = count($images);
        if ($count === 0) {
            throw new RuntimeException('Cannot render slideshow without images.');
        }

        $directory = dirname($job->outputPath());
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create video directory "%s".', $directory));
        }

        $command = $this->buildCommand($images, $job->outputPath());

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
     * @param list<string> $images
     *
     * @return list<string>
     */
    private function buildCommand(array $images, string $output): array
    {
        if (count($images) === 1) {
            return $this->buildSingleImageCommand($images[0], $output);
        }

        return $this->buildMultiImageCommand($images, $output);
    }

    private function buildSingleImageCommand(string $image, string $output): array
    {
        $filter = sprintf(
            '[0:v]scale=%1$d:%2$d:force_original_aspect_ratio=decrease,' .
            'pad=%1$d:%2$d:(ow-iw)/2:(oh-ih)/2:black,format=yuv420p,setsar=1,' .
            'trim=duration=%3$.3f,setpts=PTS-STARTPTS[vout]',
            $this->width,
            $this->height,
            max(0.1, $this->slideDuration)
        );

        return [
            $this->ffmpegBinary,
            '-y',
            '-loglevel',
            'error',
            '-loop',
            '1',
            '-t',
            sprintf('%0.3f', max(0.1, $this->slideDuration)),
            '-i',
            $image,
            '-filter_complex',
            $filter,
            '-map',
            '[vout]',
            '-movflags',
            '+faststart',
            '-pix_fmt',
            'yuv420p',
            '-an',
            $output,
        ];
    }

    /**
     * @param list<string> $images
     *
     * @return list<string>
     */
    private function buildMultiImageCommand(array $images, string $output): array
    {
        $command = [$this->ffmpegBinary, '-y', '-loglevel', 'error'];
        $durationWithOverlap = max(0.1, $this->slideDuration + $this->transitionDuration);

        foreach ($images as $image) {
            $command = array_merge($command, [
                '-loop',
                '1',
                '-t',
                sprintf('%0.3f', $durationWithOverlap),
                '-i',
                $image,
            ]);
        }

        $filters = array_map(
            fn (int $index): string => sprintf(
                '[%1$d:v]scale=%2$d:%3$d:force_original_aspect_ratio=decrease,' .
                'pad=%2$d:%3$d:(ow-iw)/2:(oh-ih)/2:black,format=yuv420p,setsar=1,' .
                'trim=duration=%4$.3f,setpts=PTS-STARTPTS[s%1$d]',
                $index,
                $this->width,
                $this->height,
                $durationWithOverlap
            ),
            array_keys($images),
        );

        $transitionCount = count($this->transitions);
        $current         = '[s0]';
        $offset          = max(0.1, $this->slideDuration);
        $imageCount      = count($images);

        foreach (array_keys($images) as $index) {
            if ($index === 0) {
                continue;
            }

            $transition = $this->transitions[($index - 1) % $transitionCount] ?? 'fade';
            if ($transition === '') {
                $transition = 'fade';
            }

            $targetLabel = $index === $imageCount - 1 ? '[vout]' : sprintf('[tmp%d]', $index);
            $filters[] = sprintf(
                '%s[s%d]xfade=transition=%s:duration=%0.3f:offset=%0.3f:shortest=1%s',
                $current,
                $index,
                $transition,
                max(0.1, $this->transitionDuration),
                $offset,
                $targetLabel
            );
            $current = $targetLabel;
            $offset += max(0.1, $this->slideDuration);
        }

        $filterComplex = implode(';', $filters);

        $command[] = '-filter_complex';
        $command[] = $filterComplex;
        $command[] = '-map';
        $command[] = '[vout]';
        $command[] = '-movflags';
        $command[] = '+faststart';
        $command[] = '-pix_fmt';
        $command[] = 'yuv420p';
        $command[] = '-an';
        $command[] = $output;

        return $command;
    }
}
