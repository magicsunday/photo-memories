<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Monitoring\Contract\JobMonitoringEmitterInterface;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function sprintf;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const PHP_BINARY;

/**
 * Coordinates slideshow generation.
 */
final readonly class SlideshowVideoManager implements SlideshowVideoManagerInterface
{
    private string $videoDirectory;

    private float $slideDuration;

    private float $transitionDuration;

    private SlideshowVideoGeneratorInterface $generator;

    private ?JobMonitoringEmitterInterface $monitoringEmitter;

    /**
     * @var list<string>
     */
    private array $transitions;

    private ?string $musicTrack;

    private string $consoleEntryPoint;

    private string $phpBinary;

    private string $consoleWorkingDirectory;

    /**
     * @param list<string> $transitions
     */
    public function __construct(
        string $videoDirectory,
        float $slideDuration,
        float $transitionDuration,
        SlideshowVideoGeneratorInterface $generator,
        array $transitions = [],
        ?string $musicTrack = null,
        ?JobMonitoringEmitterInterface $monitoringEmitter = null,
        ?string $consoleEntryPoint = null,
        ?string $phpBinary = null,
        ?string $consoleWorkingDirectory = null,
    ) {
        $this->videoDirectory     = $videoDirectory;
        $this->generator           = $generator;
        $this->monitoringEmitter  = $monitoringEmitter;

        $this->slideDuration = $slideDuration > 0.0 ? $slideDuration : 3.5;
        $this->transitionDuration = $transitionDuration >= 0.0 ? $transitionDuration : 0.8;

        $this->transitions = $this->sanitizeTransitions($transitions);

        $musicTrack       = $musicTrack !== null ? trim($musicTrack) : '';
        $this->musicTrack = $musicTrack === '' ? null : $musicTrack;

        $defaultEntryPoint = dirname(__DIR__, 2) . '/Memories.php';
        $this->consoleEntryPoint = $consoleEntryPoint !== null && trim($consoleEntryPoint) !== ''
            ? $consoleEntryPoint
            : $defaultEntryPoint;

        $this->phpBinary = $phpBinary !== null && trim($phpBinary) !== ''
            ? $phpBinary
            : PHP_BINARY;

        $defaultWorkingDirectory = dirname(__DIR__, 3);
        $this->consoleWorkingDirectory = $consoleWorkingDirectory !== null && trim($consoleWorkingDirectory) !== ''
            ? $consoleWorkingDirectory
            : $defaultWorkingDirectory;
    }

    /**
     * @param array<int, mixed> $transitions
     *
     * @return list<string>
     */
    private function sanitizeTransitions(array $transitions): array
    {
        $sanitized = [];
        foreach ($transitions as $transition) {
            if (!is_string($transition)) {
                continue;
            }

            $trimmed = trim($transition);
            if ($trimmed === '') {
                continue;
            }

            $sanitized[] = $trimmed;
        }

        return $sanitized;
    }

    /**
     * @param list<int>        $memberIds
     * @param array<int,Media> $mediaMap
     */
    public function ensureForItem(string $itemId, array $memberIds, array $mediaMap): SlideshowVideoStatus
    {
        $slides = $this->collectSlides($memberIds, $mediaMap);
        if ($slides === []) {
            $this->emitMonitoring('skipped', [
                'itemId'      => $itemId,
                'memberCount' => count($memberIds),
                'reason'      => 'no_slides',
            ]);

            return SlideshowVideoStatus::unavailable($this->slideDuration);
        }

        $videoPath = $this->buildVideoPath($itemId);
        $lockPath  = $this->buildLockPath($videoPath);
        $errorPath = $this->buildErrorPath($videoPath);
        $jobPath   = $this->buildJobPath($videoPath);

        $status = $this->getStatusForItem($itemId);
        if ($status->status() === SlideshowVideoStatus::STATUS_READY) {
            $this->emitMonitoring('ready', [
                'itemId'    => $itemId,
                'source'    => 'existing',
                'videoPath' => $videoPath,
            ]);

            return $status;
        }

        if ($status->status() === SlideshowVideoStatus::STATUS_GENERATING) {
            $this->emitMonitoring('generating', [
                'itemId' => $itemId,
                'reason' => 'lock_exists',
            ]);

            return $status;
        }

        if ($status->status() === SlideshowVideoStatus::STATUS_ERROR) {
            $this->emitMonitoring('failed', [
                'itemId' => $itemId,
                'reason' => 'previous_error',
            ]);

            return $status;
        }

        $images     = array_map(static fn (array $slide): string => $slide['path'], $slides);
        $storyboard = $this->buildStoryboard($slides);

        $this->ensureVideoDirectory();

        $job = new SlideshowJob(
            $itemId,
            $jobPath,
            $videoPath,
            $lockPath,
            $errorPath,
            $images,
            $storyboard['slides'],
            $storyboard['transitionDuration'],
            $storyboard['music'],
        );
        $writtenBytes = file_put_contents($jobPath, $job->toJson(), LOCK_EX);
        if ($writtenBytes === false) {
            throw new ProcessRuntimeException(sprintf('Job file "%s" could not be written.', $jobPath));
        }

        try {
            $processId = $this->dispatchJob($job);
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if ($message === '') {
                $message = 'Video konnte nicht erzeugt werden.';
            }

            file_put_contents($errorPath, $message, LOCK_EX);

            if (is_file($lockPath)) {
                unlink($lockPath);
            }

            if (is_file($jobPath)) {
                unlink($jobPath);
            }

            $this->emitMonitoring('failed', [
                'itemId'  => $itemId,
                'reason'  => 'dispatch_failed',
                'message' => $message,
            ]);

            return SlideshowVideoStatus::error($message, $this->slideDuration);
        }

        $this->emitMonitoring('queued', [
            'itemId'              => $itemId,
            'slideCount'          => count($slides),
            'videoPath'           => $videoPath,
            'music'               => $storyboard['music'],
            'transitionDuration'  => $storyboard['transitionDuration'],
            'mode'                => 'immediate',
            'processId'           => $processId,
        ]);

        return SlideshowVideoStatus::generating($this->slideDuration);
    }

    public function getStatusForItem(string $itemId): SlideshowVideoStatus
    {
        $videoPath = $this->buildVideoPath($itemId);
        if (is_file($videoPath)) {
            return SlideshowVideoStatus::ready($this->buildVideoUrl($itemId), $this->slideDuration);
        }

        $errorPath = $this->buildErrorPath($videoPath);
        if (is_file($errorPath)) {
            $message = file_get_contents($errorPath);
            $message = is_string($message) && $message !== '' ? $message : 'Video konnte nicht erzeugt werden.';

            return SlideshowVideoStatus::error($message, $this->slideDuration);
        }

        $lockPath = $this->buildLockPath($videoPath);
        if (is_file($lockPath)) {
            return SlideshowVideoStatus::generating($this->slideDuration);
        }

        return SlideshowVideoStatus::unavailable($this->slideDuration);
    }

    private function dispatchJob(SlideshowJob $job): ?int
    {
        $lockContent = (string) getmypid();
        $lockResult  = file_put_contents($job->lockPath(), $lockContent, LOCK_EX);
        if ($lockResult === false) {
            throw new ProcessRuntimeException(sprintf('Lock file "%s" could not be created.', $job->lockPath()));
        }

        $process = new Process([
            $this->phpBinary,
            $this->consoleEntryPoint,
            'slideshow:generate',
            $job->jobFile(),
        ], $this->consoleWorkingDirectory);
        $process->disableOutput();
        $process->setTimeout(null);
        $process->start();

        return $process->getPid();
    }

    public function resolveVideoPath(string $itemId): ?string
    {
        $path = $this->buildVideoPath($itemId);

        return is_file($path) ? $path : null;
    }

    /**
     * @param list<int>        $memberIds
     * @param array<int,Media> $mediaMap
     *
     * @return list<string>
     */
    /**
     * @param list<int>        $memberIds
     * @param array<int,Media> $mediaMap
     *
     * @return list<array{mediaId:int,path:string}>
     */
    private function collectSlides(array $memberIds, array $mediaMap): array
    {
        $slides = [];
        foreach ($memberIds as $memberId) {
            $media = $mediaMap[$memberId] ?? null;
            if (!$media instanceof Media) {
                continue;
            }

            $path = $media->getPath();
            if ($path !== '' && is_file($path)) {
                $slides[] = [
                    'mediaId' => $memberId,
                    'path'    => $path,
                ];
            }
        }

        return $slides;
    }

    /**
     * @param list<array{mediaId:int,path:string}> $slides
     */
    private function buildStoryboard(array $slides): array
    {
        $storySlides     = [];
        $transitionCount = count($this->transitions);

        foreach ($slides as $index => $slide) {
            $storySlide = [
                'mediaId'    => $slide['mediaId'],
                'image'      => $slide['path'],
                'duration'   => $this->slideDuration,
                'transition' => null,
            ];

            if ($transitionCount > 0) {
                $transition = $this->transitions[$index % $transitionCount] ?? null;
                if (is_string($transition) && $transition !== '') {
                    $storySlide['transition'] = $transition;
                }
            }

            $storySlides[] = $storySlide;
        }

        $payload = [
            'slides'             => $storySlides,
            'transitionDuration' => $this->transitionDuration,
            'music'              => $this->musicTrack,
        ];

        return $payload;
    }

    private function buildVideoPath(string $itemId): string
    {
        return $this->videoDirectory . DIRECTORY_SEPARATOR . $itemId . '.mp4';
    }

    private function buildLockPath(string $videoPath): string
    {
        return $videoPath . '.lock';
    }

    private function buildJobPath(string $videoPath): string
    {
        return $videoPath . '.job.json';
    }

    private function buildErrorPath(string $videoPath): string
    {
        return $videoPath . '.error.log';
    }

    private function buildVideoUrl(string $itemId): string
    {
        return sprintf('/api/feed/%s/video', $itemId);
    }

    private function ensureVideoDirectory(): void
    {
        if (is_dir($this->videoDirectory)) {
            return;
        }

        if (!mkdir($this->videoDirectory, 0775, true) && !is_dir($this->videoDirectory)) {
            throw new ProcessRuntimeException(sprintf('Video directory "%s" could not be created.', $this->videoDirectory));
        }
    }

    private function emitMonitoring(string $status, array $context = []): void
    {
        if ($this->monitoringEmitter === null) {
            return;
        }

        $this->monitoringEmitter->emit('slideshow.generate', $status, $context);
    }
}
