<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

use function array_find;
use function basename;
use function pathinfo;
use function preg_match;
use function sprintf;

use const PATHINFO_FILENAME;

/**
 * Parses capture timestamps from common filename patterns.
 */
final class FilenameDateParser
{
    public function parse(string $filepath, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $filename = basename($filepath);
        $stem     = pathinfo($filename, PATHINFO_FILENAME);

        $patterns = [
            '/(?<!\d)(\d{4})(\d{2})(\d{2})[_-]?(\d{2})(\d{2})(\d{2})(?!\d)/',
            '/(?<!\d)(\d{4})[-_.](\d{2})[-_.](\d{2})[ T_-](\d{2})[-_.](\d{2})[-_.](\d{2})(?!\d)/',
            '/(?:IMG|DSC|VID|MOV|PXL|PHOTO)[_-]?(\d{4})(\d{2})(\d{2})[_-]?(\d{2})(\d{2})(\d{2})/i',
        ];

        /** @var DateTimeImmutable|null $dateTime */
        $dateTime = null;

        $matchedPattern = array_find(
            $patterns,
            function (string $pattern) use ($stem, $timezone, &$dateTime): bool {
                $matches = [];
                if (preg_match($pattern, $stem, $matches) !== 1) {
                    return false;
                }

                $date = sprintf(
                    '%s-%s-%s %s:%s:%s',
                    $matches[1],
                    $matches[2],
                    $matches[3],
                    $matches[4],
                    $matches[5],
                    $matches[6],
                );

                try {
                    $dateTime = new DateTimeImmutable($date, $timezone);
                } catch (Exception) {
                    return false;
                }

                return true;
            }
        );

        if ($matchedPattern === null) {
            return null;
        }

        return $dateTime;
    }
}
