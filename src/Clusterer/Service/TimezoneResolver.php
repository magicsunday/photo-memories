<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Clusterer\Support\VacationTimezoneTrait;
use MagicSunday\Memories\Entity\Media;

use function is_string;

/**
 * Default timezone resolver used by the vacation clustering pipeline.
 */
final readonly class TimezoneResolver implements TimezoneResolverInterface
{
    use VacationTimezoneTrait {
        resolveMediaTimezone as private traitResolveMediaTimezone;
        resolveSummaryTimezone as private traitResolveSummaryTimezone;
        createTimezoneFromOffset as private traitCreateTimezoneFromOffset;
    }

    public function __construct(private readonly string $timezone = 'Europe/Berlin')
    {
        if ($this->timezone === '') {
            throw new InvalidArgumentException('timezone must not be empty.');
        }
    }

    public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
    {
        return $this->traitResolveMediaTimezone($media, $takenAt, $home);
    }

    public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
    {
        return $this->traitResolveSummaryTimezone($summary, $home);
    }

    public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
    {
        if ($offsetVotes !== []) {
            $bestOffset = null;
            $bestCount  = -1;
            foreach ($offsetVotes as $offset => $count) {
                if ($count > $bestCount) {
                    $bestCount  = $count;
                    $bestOffset = (int) $offset;
                }
            }

            if ($bestOffset !== null) {
                return $bestOffset;
            }
        }

        return $home['timezone_offset'] ?? null;
    }

    public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
    {
        if ($identifierVotes !== []) {
            $bestIdentifier = null;
            $bestCount      = -1;
            foreach ($identifierVotes as $identifier => $count) {
                if ($count > $bestCount && is_string($identifier) && $identifier !== '') {
                    $bestCount      = $count;
                    $bestIdentifier = $identifier;
                }
            }

            if ($bestIdentifier !== null) {
                return $bestIdentifier;
            }
        }

        if ($offset !== null) {
            return $this->traitCreateTimezoneFromOffset($offset)->getName();
        }

        $homeOffset = $home['timezone_offset'] ?? null;
        if ($homeOffset !== null) {
            return $this->traitCreateTimezoneFromOffset($homeOffset)->getName();
        }

        return $this->timezone;
    }
}
