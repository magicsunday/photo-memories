<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Slideshow;

use function round;

/**
 * Value object describing the current slideshow video state.
 */
final readonly class SlideshowVideoStatus
{
    public const string STATUS_READY = 'bereit';

    public const string STATUS_GENERATING = 'in_erstellung';

    public const string STATUS_ERROR = 'fehlgeschlagen';

    public const string STATUS_UNAVAILABLE = 'nicht_verfuegbar';

    public function __construct(
        private readonly string $status,
        private readonly ?string $url,
        private readonly ?string $message,
        private readonly float $secondsPerImage,
    ) {
    }

    public static function ready(string $url, float $secondsPerImage): self
    {
        return new self(self::STATUS_READY, $url, null, $secondsPerImage);
    }

    public static function generating(float $secondsPerImage): self
    {
        return new self(self::STATUS_GENERATING, null, 'Video wird erstellt …', $secondsPerImage);
    }

    public static function unavailable(float $secondsPerImage): self
    {
        return new self(self::STATUS_UNAVAILABLE, null, null, $secondsPerImage);
    }

    public static function error(string $message, float $secondsPerImage): self
    {
        return new self(self::STATUS_ERROR, null, $message, $secondsPerImage);
    }

    public function toArray(): array
    {
        $payload = [
            'status'               => $this->status,
            'meldung'              => $this->message,
            'dauerProBildSekunden' => round($this->secondsPerImage, 2),
        ];

        if ($this->url !== null) {
            $payload['url'] = $this->url;
        }

        return $payload;
    }

    public function status(): string
    {
        return $this->status;
    }
}
