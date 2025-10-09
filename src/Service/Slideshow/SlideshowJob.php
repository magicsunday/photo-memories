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
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error_msg;
use function sprintf;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Represents a scheduled slideshow generation job.
 */
final readonly class SlideshowJob
{
    /**
     * @param list<string>                                                                     $images
     * @param list<array{image:string,mediaId:int|null,duration:float,transition:string|null}> $slides
     */
    public function __construct(
        private string $id,
        private string $jobFile,
        private string $outputPath,
        private string $lockPath,
        private string $errorPath,
        private array $images,
        private array $slides,
        private ?float $transitionDuration,
        private ?string $audioTrack,
        private ?string $title = null,
        private ?string $subtitle = null,
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
     * @return list<array{image:string,mediaId:int|null,duration:float,transition:string|null}>
     */
    public function slides(): array
    {
        return $this->slides;
    }

    public function transitionDuration(): ?float
    {
        return $this->transitionDuration;
    }

    public function audioTrack(): ?string
    {
        return $this->audioTrack;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function subtitle(): ?string
    {
        return $this->subtitle;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $storyboard = [
            'slides' => array_map(
                static function (array $slide): array {
                    $payload = [
                        'image'    => $slide['image'],
                        'duration' => $slide['duration'],
                    ];

                    if ($slide['mediaId'] !== null) {
                        $payload['mediaId'] = $slide['mediaId'];
                    }

                    if ($slide['transition'] !== null) {
                        $payload['transition'] = $slide['transition'];
                    }

                    return $payload;
                },
                $this->slides,
            ),
        ];

        if ($this->transitionDuration !== null) {
            $storyboard['transitionDuration'] = $this->transitionDuration;
        }

        if ($this->audioTrack !== null) {
            $storyboard['music'] = $this->audioTrack;
        }

        $payload = [
            'id'         => $this->id,
            'output'     => $this->outputPath,
            'lock'       => $this->lockPath,
            'error'      => $this->errorPath,
            'images'     => $this->images,
            'storyboard' => $storyboard,
        ];

        if ($this->title !== null) {
            $payload['title'] = $this->title;
        }

        if ($this->subtitle !== null) {
            $payload['subtitle'] = $this->subtitle;
        }

        return $payload;
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

        $storyboardRaw = $payload['storyboard'] ?? [];
        if (!is_array($storyboardRaw)) {
            $storyboardRaw = [];
        }

        $storyboard = self::normaliseStoryboard($storyboardRaw, $images);

        $title    = self::normaliseOptionalString($payload['title'] ?? null);
        $subtitle = self::normaliseOptionalString($payload['subtitle'] ?? null);

        return new self(
            $id,
            $path,
            $output,
            $lock,
            $error,
            $images,
            $storyboard['slides'],
            $storyboard['transitionDuration'],
            $storyboard['music'],
            $title,
            $subtitle,
        );
    }

    /**
     * @param array<string,mixed> $storyboard
     * @param list<string>        $images
     *
     * @return array{slides:list<array{image:string,mediaId:int|null,duration:float,transition:string|null}>,transitionDuration:float|null,music:string|null}
     */
    private static function normaliseStoryboard(array $storyboard, array $images): array
    {
        $slidesRaw = $storyboard['slides'] ?? [];
        $slides    = [];

        if (is_array($slidesRaw)) {
            foreach ($slidesRaw as $index => $slide) {
                if (!is_array($slide)) {
                    continue;
                }

                $image = $slide['image'] ?? ($images[$index] ?? null);
                if (!is_string($image) || $image === '') {
                    continue;
                }

                $durationRaw = $slide['duration'] ?? null;
                $duration    = is_numeric($durationRaw) ? (float) $durationRaw : 0.0;
                if ($duration <= 0.0) {
                    $duration = 0.0;
                }

                $mediaRaw = $slide['mediaId'] ?? null;
                $mediaId  = null;
                if (is_int($mediaRaw)) {
                    $mediaId = $mediaRaw;
                } elseif (is_numeric($mediaRaw)) {
                    $mediaId = (int) $mediaRaw;
                }

                $transitionRaw = $slide['transition'] ?? null;
                $transition    = null;
                if (is_string($transitionRaw)) {
                    $trimmed = trim($transitionRaw);
                    if ($trimmed !== '') {
                        $transition = $trimmed;
                    }
                }

                $slides[] = [
                    'image'      => $image,
                    'mediaId'    => $mediaId,
                    'duration'   => $duration,
                    'transition' => $transition,
                ];
            }
        }

        if ($slides === []) {
            foreach ($images as $image) {
                $slides[] = [
                    'image'      => $image,
                    'mediaId'    => null,
                    'duration'   => 0.0,
                    'transition' => null,
                ];
            }
        }

        $transitionRaw      = $storyboard['transitionDuration'] ?? null;
        $transitionDuration = null;
        if (is_numeric($transitionRaw)) {
            $transitionDuration = (float) $transitionRaw;
            if ($transitionDuration <= 0.0) {
                $transitionDuration = null;
            }
        }

        $musicRaw = $storyboard['music'] ?? null;
        if (!is_string($musicRaw)) {
            $musicRaw = $storyboard['audio'] ?? null;
        }

        $music = null;
        if (is_string($musicRaw)) {
            $trimmed = trim($musicRaw);
            if ($trimmed !== '') {
                $music = $trimmed;
            }
        }

        return [
            'slides'             => $slides,
            'transitionDuration' => $transitionDuration,
            'music'              => $music,
        ];
    }

    private static function normaliseOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private static function requireString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Job payload field "%s" must be a non-empty string.', $field));
        }

        return $value;
    }
}
