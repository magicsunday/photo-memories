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

use function array_map;
use function count;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function fclose;
use function function_exists;
use function getmypid;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_resource;
use function is_string;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function sprintf;
use function time;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

/**
 * Coordinates slideshow generation.
 */
final readonly class SlideshowVideoManager implements SlideshowVideoManagerInterface
{
    private const int STALE_LOCK_THRESHOLD_SECONDS = 120;

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
        $this->transitionDuration = $transitionDuration >= 0.0 ? $transitionDuration : 0.75;

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
    public function ensureForItem(
        string $itemId,
        array $memberIds,
        array $mediaMap,
        ?string $title = null,
        ?string $subtitle = null,
    ): SlideshowVideoStatus
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
            if ($this->shouldResetStalledJob($lockPath, $jobPath)) {
                $this->cleanupStalledJob($itemId, $lockPath, $jobPath, $errorPath);

                // fall through to schedule a fresh job immediately
            } else {
                $this->emitMonitoring('generating', [
                    'itemId' => $itemId,
                    'reason' => 'lock_exists',
                ]);

                return $status;
            }
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

        $title    = $this->normaliseMetadata($title);
        $subtitle = $this->normaliseMetadata($subtitle);

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
            $title,
            $subtitle,
        );
        $writtenBytes = file_put_contents($jobPath, $job->toJson(), LOCK_EX);
        if ($writtenBytes === false) {
            throw new ProcessRuntimeException(sprintf('Job file "%s" could not be written.', $jobPath));
        }

        try {
            $this->dispatchJob($job);
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

        $status = $this->getStatusForItem($itemId);

        $this->emitMonitoring('generated', [
            'itemId'              => $itemId,
            'slideCount'          => count($slides),
            'videoPath'           => $videoPath,
            'music'               => $storyboard['music'],
            'transitionDuration'  => $storyboard['transitionDuration'],
            'mode'                => 'inline',
        ]);

        return $status;
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

    private function dispatchJob(SlideshowJob $job): void
    {
        $lockContent = (string) getmypid();
        $lockResult  = file_put_contents($job->lockPath(), $lockContent, LOCK_EX);
        if ($lockResult === false) {
            throw new ProcessRuntimeException(sprintf('Lock file "%s" could not be created.', $job->lockPath()));
        }

        try {
            $this->generator->generate($job);
        } catch (Throwable $exception) {
            if (is_file($job->lockPath())) {
                unlink($job->lockPath());
            }

            throw $exception;
        } finally {
            if (is_file($job->jobFile())) {
                unlink($job->jobFile());
            }
        }

        if (is_file($job->errorPath())) {
            unlink($job->errorPath());
        }

        if (is_file($job->lockPath())) {
            unlink($job->lockPath());
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
        $storySlides = [];

        $mediaIds          = array_map(static fn (array $slide): int => (int) $slide['mediaId'], $slides);
        $transitionSequence = TransitionSequenceGenerator::generate(
            $this->transitions,
            $mediaIds,
            count($slides)
        );
        $sequenceIndex = 0;

        foreach ($slides as $slide) {
            $storySlide = [
                'mediaId'    => $slide['mediaId'],
                'image'      => $slide['path'],
                'duration'   => $this->slideDuration,
                'transition' => null,
            ];

            $transition = $transitionSequence[$sequenceIndex] ?? null;
            if (is_string($transition) && $transition !== '') {
                $storySlide['transition'] = $transition;
            }

            ++$sequenceIndex;

            $storySlides[] = $storySlide;
        }

        $payload = [
            'slides'             => $storySlides,
            'transitionDuration' => $this->transitionDuration,
            'music'              => $this->musicTrack,
        ];

        return $payload;
    }

    private function normaliseMetadata(?string $value): ?string
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

    private function shouldResetStalledJob(string $lockPath, string $jobPath): bool
    {
        if (!is_file($lockPath) || !is_file($jobPath)) {
            return false;
        }

        $lockContent = file_get_contents($lockPath);
        $pid         = $this->extractProcessId($lockContent);

        if ($pid !== null && $this->isProcessRunning($pid)) {
            return false;
        }

        $lockAge = time() - max(filemtime($lockPath), filemtime($jobPath));
        if ($lockAge < self::STALE_LOCK_THRESHOLD_SECONDS) {
            return false;
        }

        return true;
    }

    private function cleanupStalledJob(string $itemId, string $lockPath, string $jobPath, string $errorPath): void
    {
        if (is_file($lockPath)) {
            unlink($lockPath);
        }

        if (is_file($jobPath)) {
            unlink($jobPath);
        }

        if (is_file($errorPath)) {
            unlink($errorPath);
        }

        $this->emitMonitoring('reset', [
            'itemId' => $itemId,
            'reason'  => 'stale_lock',
            'lock'    => $lockPath,
            'job'     => $jobPath,
            'error'   => $errorPath,
        ]);
    }

    private function extractProcessId(mixed $value): ?int
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            $pid = (int) $trimmed;

            return $pid > 0 ? $pid : null;
        }

        if (preg_match('/pid\s*:\s*(\d+)/i', $trimmed, $matches) === 1) {
            $pid = (int) $matches[1];

            return $pid > 0 ? $pid : null;
        }

        return null;
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (function_exists('proc_open')) {
            $handle = @proc_open(sprintf('ps -p %d', $pid), [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (is_resource($handle)) {
                $status = proc_get_status($handle);
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($handle);

                return $status['running'] ?? false;
            }
        }

        return false;
    }
}
