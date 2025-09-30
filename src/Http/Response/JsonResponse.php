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
     */
    public function __construct(array|int|float|string|bool|null $data, int $statusCode = 200, array $headers = [])
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';

        try {
            $encoded = json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            );
            parent::__construct($encoded ?: 'null', $statusCode, $headers);

            return;
        } catch (JsonException) {
            $fallback = json_encode(
                ['error' => 'Failed to encode response'],
                JSON_THROW_ON_ERROR
            );
            parent::__construct($fallback ?: 'null', 500, $headers);
        }
    }
}
