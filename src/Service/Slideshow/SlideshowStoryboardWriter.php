<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;

use function file_put_contents;
use function json_encode;
use function is_dir;
use function mkdir;
use function rtrim;
use function sprintf;

use const DIRECTORY_SEPARATOR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Persists slideshow storyboard payloads to disk.
 */
final readonly class SlideshowStoryboardWriter
{
    public function __construct(private string $storyboardDirectory)
    {
    }

    public function write(SlideshowJob $job): string
    {
        $baseDirectory = rtrim($this->storyboardDirectory, DIRECTORY_SEPARATOR);
        if ($baseDirectory === '') {
            throw new ProcessRuntimeException('Storyboard directory must not be empty.');
        }

        if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0775, true) && !is_dir($baseDirectory)) {
            throw new ProcessRuntimeException(sprintf('Storyboard directory "%s" could not be created.', $baseDirectory));
        }

        $targetDirectory = $baseDirectory . DIRECTORY_SEPARATOR . $job->id();
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new ProcessRuntimeException(sprintf('Storyboard directory "%s" could not be created.', $targetDirectory));
        }

        $storyboard = [
            'slides' => $job->slides(),
        ];

        $transitionDuration = $job->transitionDuration();
        if ($transitionDuration !== null) {
            $storyboard['transitionDuration'] = $transitionDuration;
        }

        $transitionDurations = $job->transitionDurations();
        if ($transitionDurations !== []) {
            $storyboard['transitionDurations'] = $transitionDurations;
        }

        $audioTrack = $job->audioTrack();
        if ($audioTrack !== null) {
            $storyboard['music'] = $audioTrack;
        }

        $payload = [
            'id'         => $job->id(),
            'title'      => $job->title(),
            'subtitle'   => $job->subtitle(),
            'images'     => $job->images(),
            'storyboard' => $storyboard,
        ];

        $path    = $targetDirectory . DIRECTORY_SEPARATOR . 'storyboard.json';
        $written = file_put_contents(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            LOCK_EX,
        );

        if ($written === false) {
            throw new ProcessRuntimeException(sprintf('Storyboard file "%s" could not be written.', $path));
        }

        return $path;
    }
}
