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

use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error_msg;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Represents a scheduled slideshow generation job.
 */
final readonly class SlideshowJob
{
    /**
     * @param list<string> $images
     */
    public function __construct(
        private string $id,
        private string $jobFile,
        private string $outputPath,
        private string $lockPath,
        private string $errorPath,
        private array $images,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function jobFile(): string
    {
        return $this->jobFile;
    }

    public function outputPath(): string
    {
        return $this->outputPath;
    }

    public function lockPath(): string
    {
        return $this->lockPath;
    }

    public function errorPath(): string
    {
        return $this->errorPath;
    }

    /**
     * @return list<string>
     */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'     => $this->id,
            'output' => $this->outputPath,
            'lock'   => $this->lockPath,
            'error'  => $this->errorPath,
            'images' => $this->images,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    public static function fromJsonFile(string $path): self
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read job file "%s".', $path));
        }

        $payload = json_decode(
            $contents,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($payload)) {
            throw new RuntimeException(sprintf('Invalid job description in "%s": %s', $path, json_last_error_msg()));
        }

        $id     = self::requireString($payload['id'] ?? null, 'id');
        $output = self::requireString($payload['output'] ?? null, 'output');
        $lock   = self::requireString($payload['lock'] ?? null, 'lock');
        $error  = self::requireString($payload['error'] ?? null, 'error');

        $imagesRaw = $payload['images'] ?? [];
        if (!is_array($imagesRaw)) {
            throw new RuntimeException('Job payload "images" must be an array.');
        }

        $images = [];
        foreach ($imagesRaw as $image) {
            if (!is_string($image) || $image === '') {
                continue;
            }

            $images[] = $image;
        }

        if ($images === []) {
            throw new RuntimeException('Job payload does not contain any usable images.');
        }

        return new self($id, $path, $output, $lock, $error, $images);
    }

    private static function requireString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Job payload field "%s" must be a non-empty string.', $field));
        }

        return $value;
    }
}
