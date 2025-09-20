<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Model;

use DateTimeImmutable;

/**
 * Class MediaItem.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-memories/
 */
class MediaItem
{
    public string $path;

    public string $type;

    // "image" oder "video"
    public float $score;

    public DateTimeImmutable $createdAt;

    /** @var array<int,mixed> */
    public array $faces = [];

    public ?float $latitude = null;

    public ?float $longitude = null;

    public ?string $cameraModel = null;

    public function __construct(string $path, string $type, DateTimeImmutable $createdAt, float $score = 0.0)
    {
        $this->path      = $path;
        $this->type      = $type;
        $this->createdAt = $createdAt;
        $this->score     = $score;
    }
}
