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
 * Class Memory.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-memories/
 */
class Memory
{
    public string $title;

    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    /** @var MediaItem[] */
    public array $items = [];

    public ?MediaItem $cover = null;

    public ?string $city = null;

    public ?string $country = null;

    /**
     * Constructor.
     *
     * @param string            $title
     * @param DateTimeImmutable $start
     * @param DateTimeImmutable $end
     * @param MediaItem[]       $items
     */
    public function __construct(string $title, DateTimeImmutable $start, DateTimeImmutable $end, array $items)
    {
        $this->title = $title;
        $this->start = $start;
        $this->end   = $end;
        $this->items = $items;

        usort(
            $this->items,
            static fn ($a, $b): int => $b->score <=> $a->score
        );

        $this->cover = $this->items[0] ?? null;
    }

    /**
     * @return array<string, array<string>|string|null>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'start' => $this->start->format(DATE_ATOM),
            'end'   => $this->end->format(DATE_ATOM),
            'cover' => $this->cover?->path,
            'items' => array_map(static fn (MediaItem $m): string => $m->path, $this->items),
        ];
    }
}
