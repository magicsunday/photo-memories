<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Value;

use InvalidArgumentException;

use function is_array;
use function max;
use function min;
use function trim;

/**
 * Value object describing a place identifier including provider metadata.
 */
final class PlaceId
{
    private string $provider;

    private string $identifier;

    private ?float $confidence;

    /**
     * @var array<string, mixed>
     */
    private array $meta;

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(string $provider, string $identifier, ?float $confidence = null, array $meta = [])
    {
        $provider   = trim($provider);
        $identifier = trim($identifier);

        if ($provider === '') {
            throw new InvalidArgumentException('PlaceId provider must not be empty.');
        }

        if ($identifier === '') {
            throw new InvalidArgumentException('PlaceId identifier must not be empty.');
        }

        if ($confidence !== null) {
            $clamped = max(0.0, min(1.0, $confidence));
            if ($clamped !== $confidence) {
                throw new InvalidArgumentException('PlaceId confidence must be between 0 and 1.');
            }
        }

        $this->provider   = $provider;
        $this->identifier = $identifier;
        $this->confidence = $confidence;
        $this->meta       = $meta;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $provider = (string) ($payload['provider'] ?? '');
        $identifier = (string) ($payload['id'] ?? ($payload['identifier'] ?? ''));

        $confidence = null;
        if (isset($payload['confidence'])) {
            $confidence = (float) $payload['confidence'];
        }

        $meta = [];
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            /** @var array<string, mixed> $meta */
            $meta = $payload['meta'];
        }

        return new self($provider, $identifier, $confidence, $meta);
    }

    /**
     * @return array{provider:string,id:string,confidence:float|null,meta:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'provider'   => $this->provider,
            'id'         => $this->identifier,
            'confidence' => $this->confidence,
            'meta'       => $this->meta,
        ];
    }
}
