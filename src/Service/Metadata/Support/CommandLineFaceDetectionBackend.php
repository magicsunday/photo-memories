<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use MagicSunday\Memories\Entity\Media;
use Symfony\Component\Process\Process;
use Throwable;

use function array_key_exists;
use function is_array;
use function is_file;
use function is_numeric;
use function json_decode;
use function max;
use function sprintf;
use function trim;

/**
 * Face-detection backend delegating to a command line utility (for example OpenCV).
 */
final class CommandLineFaceDetectionBackend implements FaceDetectionBackendInterface
{
    private string $binary;

    private ?string $cascadePath;

    private float $scaleFactor;

    private int $minNeighbors;

    private int $minSize;

    private float $timeout;

    public function __construct(
        string $binary,
        ?string $cascadePath = null,
        float $scaleFactor = 1.1,
        int $minNeighbors = 4,
        int $minSize = 72,
        float $timeout = 10.0,
    ) {
        $this->binary       = $binary !== '' ? $binary : '';
        $this->cascadePath  = $cascadePath !== null && $cascadePath !== '' ? $cascadePath : null;
        $this->scaleFactor  = $scaleFactor;
        $this->minNeighbors = $minNeighbors;
        $this->minSize      = $minSize;
        $this->timeout      = $timeout;
    }

    public function detectFaces(string $imagePath, Media $media): FaceDetectionResult
    {
        if ($this->binary === '' || !is_file($imagePath)) {
            return FaceDetectionResult::unavailable();
        }

        $command = [$this->binary, '--format=json', '--input', $imagePath];

        if ($this->cascadePath !== null) {
            $command[] = '--cascade';
            $command[] = $this->cascadePath;
        }

        if ($this->scaleFactor > 0.0) {
            $command[] = '--scale';
            $command[] = sprintf('%.2f', $this->scaleFactor);
        }

        if ($this->minNeighbors > 0) {
            $command[] = '--min-neighbors';
            $command[] = (string) $this->minNeighbors;
        }

        if ($this->minSize > 0) {
            $command[] = '--min-size';
            $command[] = (string) $this->minSize;
        }

        $process = new Process($command);

        if ($this->timeout > 0.0) {
            $process->setTimeout($this->timeout);
        }

        try {
            $process->run();
        } catch (Throwable) {
            return FaceDetectionResult::unavailable();
        }

        if (!$process->isSuccessful()) {
            return FaceDetectionResult::unavailable();
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            $output = trim($process->getErrorOutput());
        }

        if ($output === '') {
            return FaceDetectionResult::fromCount(0);
        }

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            return FaceDetectionResult::unavailable();
        }

        if (array_key_exists('count', $decoded) && is_numeric($decoded['count'])) {
            return FaceDetectionResult::fromCount(max(0, (int) $decoded['count']));
        }

        $faces = $decoded['faces'] ?? $decoded['detections'] ?? null;
        if (!is_array($faces)) {
            return FaceDetectionResult::fromCount(0);
        }

        $count = 0;
        foreach ($faces as $face) {
            if (is_array($face)) {
                ++$count;

                continue;
            }

            if (is_numeric($face)) {
                ++$count;
            }
        }

        return FaceDetectionResult::fromCount(max(0, $count));
    }
}
