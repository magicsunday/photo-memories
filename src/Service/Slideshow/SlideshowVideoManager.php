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
use Throwable;

use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function mkdir;
use function sprintf;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

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
    ) {
        $this->videoDirectory     = $videoDirectory;
        $this->generator           = $generator;
        $this->monitoringEmitter  = $monitoringEmitter;

        $this->slideDuration = $slideDuration > 0.0 ? $slideDuration : 3.5;
        $this->transitionDuration = $transitionDuration >= 0.0 ? $transitionDuration : 0.8;

        $this->transitions = $this->sanitizeTransitions($transitions);

        $musicTrack       = $musicTrack !== null ? trim($musicTrack) : '';
        $this->musicTrack = $musicTrack === '' ? null : $musicTrack;
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

        $images     = array_map(static fn (array $slide): string => $slide['path'], $slides);
        $storyboard = $this->buildStoryboard($slides);

        $videoPath = $this->buildVideoPath($itemId);
        $lockPath  = $this->buildLockPath($videoPath);
        $errorPath = $this->buildErrorPath($videoPath);
        $jobPath   = $this->buildJobPath($videoPath);

        if (is_file($videoPath)) {
            $this->emitMonitoring('ready', [
                'itemId'    => $itemId,
                'source'    => 'existing',
                'videoPath' => $videoPath,
            ]);

            return SlideshowVideoStatus::ready($this->buildVideoUrl($itemId), $this->slideDuration);
        }

        if (is_file($errorPath)) {
            $message = file_get_contents($errorPath);
            $message = is_string($message) && $message !== '' ? $message : 'Video konnte nicht erzeugt werden.';

            $this->emitMonitoring('failed', [
                'itemId' => $itemId,
                'reason' => 'previous_error',
            ]);

            return SlideshowVideoStatus::error($message, $this->slideDuration);
        }

        if (is_file($lockPath)) {
            $this->emitMonitoring('generating', [
                'itemId' => $itemId,
                'reason' => 'lock_exists',
            ]);

            return SlideshowVideoStatus::generating($this->slideDuration);
        }

        $this->ensureVideoDirectory();

        $handle = @fopen($lockPath,
            'xb'
        );
        if ($handle === false) {
            return SlideshowVideoStatus::generating($this->slideDuration);
        }

        $job                  = null;
        $cleanupAfterSuccess  = false;

        try {
            $job = new SlideshowJob($itemId, $jobPath, $videoPath, $lockPath, $errorPath, $images, $storyboard['slides'], $storyboard['transitionDuration'], $storyboard['music']);
            file_put_contents($jobPath, $job->toJson(), LOCK_EX);

            $this->emitMonitoring('queued', [
                'itemId'     => $itemId,
                'slideCount' => count($slides),
                'videoPath'  => $videoPath,
                'music'      => $storyboard['music'],
                'transitionDuration' => $storyboard['transitionDuration'],
                'mode'       => 'inline',
            ]);

            $this->generator->generate($job);
            $cleanupAfterSuccess = true;

            $this->emitMonitoring('ready', [
                'itemId'     => $itemId,
                'source'     => 'inline',
                'videoPath'  => $videoPath,
                'slideCount' => count($slides),
                'music'      => $storyboard['music'],
                'transitionDuration' => $storyboard['transitionDuration'],
            ]);

            return SlideshowVideoStatus::ready($this->buildVideoUrl($itemId), $this->slideDuration);
        } catch (Throwable $throwable) {
            $this->handleGenerationFailure($throwable, $lockPath, $errorPath, $jobPath);

            $this->emitMonitoring('failed', [
                'itemId'  => $itemId,
                'reason'  => 'exception',
                'message' => $throwable->getMessage(),
            ]);

            return SlideshowVideoStatus::error($throwable->getMessage(), $this->slideDuration);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($cleanupAfterSuccess && $job instanceof SlideshowJob) {
                $this->cleanupAfterSuccess($job);
            }
        }
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

    private function cleanupAfterSuccess(SlideshowJob $job): void
    {
        if (file_exists($job->lockPath())) {
            unlink($job->lockPath());
        }

        if (is_file($job->errorPath())) {
            unlink($job->errorPath());
        }

        if (is_file($job->jobFile())) {
            unlink($job->jobFile());
        }
    }

    private function handleGenerationFailure(Throwable $throwable, string $lockPath, string $errorPath, string $jobPath): void
    {
        if (file_exists($lockPath)) {
            unlink($lockPath);
        }

        $message = $throwable->getMessage();
        if ($message === '') {
            $message = 'Unbekannter Fehler bei der Videoerstellung.';
        }

        file_put_contents($errorPath, $message, LOCK_EX);

        if (file_exists($jobPath)) {
            unlink($jobPath);
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
