<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata;

use function is_array;

/**
 * Aggregates structured metadata sections for downstream consumers.
 *
 * @phpstan-import-type SectionValue from StructuredMetadataSection
 */
final readonly class StructuredMetadata
{
    public function __construct(
        public StructuredMetadataSection $lens,
        public StructuredMetadataSection $camera,
        public StructuredMetadataSection $image,
        public StructuredMetadataSection $exposure,
        public StructuredMetadataSection $gps,
        public StructuredMetadataSection $preview,
        public StructuredMetadataSection $interop,
        public StructuredMetadataSection $standards,
        public StructuredMetadataSection $derived,
    ) {
    }

    /**
     * @param array<string, bool|int|float|string|null|array|object> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            self::section($payload, 'lens'),
            self::section($payload, 'camera'),
            self::section($payload, 'image'),
            self::section($payload, 'exposure'),
            self::section($payload, 'gps'),
            self::section($payload, 'preview'),
            self::section($payload, 'interop'),
            self::section($payload, 'standards'),
            self::section($payload, 'derived'),
        );
    }

    /**
     * @return array<string, array<string, SectionValue>>
     */
    public function toArray(): array
    {
        return [
            'lens' => $this->lens->toArray(),
            'camera' => $this->camera->toArray(),
            'image' => $this->image->toArray(),
            'exposure' => $this->exposure->toArray(),
            'gps' => $this->gps->toArray(),
            'preview' => $this->preview->toArray(),
            'interop' => $this->interop->toArray(),
            'standards' => $this->standards->toArray(),
            'derived' => $this->derived->toArray(),
        ];
    }

    /**
     * @param array<string, bool|int|float|string|null|array|object> $payload
     */
    private static function section(array $payload, string $key): StructuredMetadataSection
    {
        $value = $payload[$key] ?? null;

        if (is_array($value) === false) {
            return StructuredMetadataSection::empty();
        }

        /** @var array<string, bool|int|float|string|null|array|object> $section */
        $section = $value;

        return StructuredMetadataSection::fromArray($section);
    }
}
