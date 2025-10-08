<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Support;

use DateTimeInterface;
use Stringable;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Immutable representation of a structured index log entry.
 */
final class IndexLogEntry
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_DEBUG = 'debug';

    /**
     * @param array<string, scalar|array<scalar>> $context
     */
    private function __construct(
        private readonly string $component,
        private readonly string $event,
        private readonly string $message,
        private readonly string $severity,
        private readonly ?string $code,
        private readonly array $context,
    ) {
    }

    /**
     * @param array<string, scalar|array<scalar>> $context
     */
    public static function info(string $component, string $event, string $message, array $context = [], ?string $code = null): self
    {
        return new self($component, $event, $message, self::SEVERITY_INFO, $code, $context);
    }

    /**
     * @param array<string, scalar|array<scalar>> $context
     */
    public static function warning(string $component, string $event, string $message, array $context = [], ?string $code = null): self
    {
        return new self($component, $event, $message, self::SEVERITY_WARNING, $code, $context);
    }

    /**
     * @param array<string, scalar|array<scalar>> $context
     */
    public static function error(string $component, string $event, string $message, array $context = [], ?string $code = null): self
    {
        return new self($component, $event, $message, self::SEVERITY_ERROR, $code, $context);
    }

    /**
     * Serialises the log entry into the canonical JSON line representation.
     */
    public function toJson(): string
    {
        $payload = [
            'component' => $this->component,
            'event' => $this->event,
            'severity' => $this->severity,
            'message' => $this->message,
        ];

        if ($this->code !== null && $this->code !== '') {
            $payload['code'] = $this->code;
        }

        $context = $this->normaliseContext($this->context);
        if ($context !== []) {
            $payload['context'] = $context;
        }

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, scalar|array<scalar>>
     */
    private function normaliseContext(array $context): array
    {
        $normalised = [];

        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if ($value instanceof DateTimeInterface) {
                $normalised[$key] = $value->format(DateTimeInterface::ATOM);

                continue;
            }

            if ($value instanceof Stringable) {
                $normalised[$key] = (string) $value;

                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $normalised[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                $normalised[$key] = $value;

                continue;
            }

            if (is_array($value)) {
                $list = [];
                foreach ($value as $entry) {
                    if (is_bool($entry) || is_int($entry) || is_float($entry) || $entry === null) {
                        $list[] = $entry;

                        continue;
                    }

                    if (is_string($entry)) {
                        $list[] = $entry;
                    }
                }

                if ($list !== []) {
                    $normalised[$key] = $list;
                }
            }
        }

        return $normalised;
    }

    /**
     * @return array{component:string,event:string,severity:string,message:string,context?:array<string,scalar|array<scalar>>,code?:string}
     */
    public function toArray(): array
    {
        $data = [
            'component' => $this->component,
            'event' => $this->event,
            'severity' => $this->severity,
            'message' => $this->message,
        ];

        if ($this->code !== null && $this->code !== '') {
            $data['code'] = $this->code;
        }

        if ($this->context !== []) {
            $data['context'] = $this->normaliseContext($this->context);
        }

        return $data;
    }
}
