<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Http\Response;

use JsonException;

use function json_encode;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/**
 * JSON response helper.
 */
final class JsonResponse extends Response
{
    /**
     * @param array<mixed>|scalar|null $data
     * @param int                      $statusCode
     * @param array                    $headers
     *
     * @throws JsonException
     */
    public function __construct(array|int|float|string|bool|null $data, int $statusCode = 200, array $headers = [])
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';

        try {
            $encoded = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            );
            $payload = $encoded === false || $encoded === '' ? 'null' : $encoded;
            parent::__construct($payload, $statusCode, $headers);

            return;
        } catch (JsonException) {
            $fallback = json_encode(
                ['error' => 'Failed to encode response'],
                JSON_THROW_ON_ERROR
            );
            $payload = $fallback === false || $fallback === '' ? 'null' : $fallback;
            parent::__construct($payload, 500, $headers);
        }
    }
}
