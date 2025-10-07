<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Metadata;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\Contract\TimezoneResolverInterface;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Service\Metadata\DaypartEnricher;
use MagicSunday\Memories\Service\Metadata\Support\CaptureTimeResolver;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class DaypartEnricherTest extends TestCase
{
    #[Test]
    public function derivesDaypartFromExplicitTimezone(): void
    {
        $resolver = new CaptureTimeResolver(new class implements TimezoneResolverInterface {
            public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
            {
                return new DateTimeZone('Pacific/Auckland');
            }

            public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
            {
                return new DateTimeZone('Pacific/Auckland');
            }

            public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
            {
                return null;
            }

            public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
            {
                return 'Pacific/Auckland';
            }
        });

        $media = $this->makeMedia(
            id: 1,
            path: '/fixtures/morning.jpg',
            takenAt: new DateTimeImmutable('2024-06-01T21:45:00+00:00'),
        );
        $media->setTzId('Pacific/Auckland');

        $enricher = new DaypartEnricher($resolver);
        $result   = $enricher->extract($media->getPath(), $media);

        $features = $result->getFeatures();
        self::assertSame('morning', $features['calendar']['daypart'] ?? null);
    }

    #[Test]
    public function classifiesLateEveningProperly(): void
    {
        $resolver = new CaptureTimeResolver(new class implements TimezoneResolverInterface {
            public function resolveMediaTimezone(Media $media, DateTimeImmutable $takenAt, array $home): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }

            public function resolveSummaryTimezone(array $summary, array $home): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }

            public function determineLocalTimezoneOffset(array $offsetVotes, array $home): ?int
            {
                return 0;
            }

            public function determineLocalTimezoneIdentifier(array $identifierVotes, array $home, ?int $offset): string
            {
                return 'UTC';
            }
        });

        $media = $this->makeMedia(
            id: 2,
            path: '/fixtures/evening.jpg',
            takenAt: new DateTimeImmutable('2024-06-01T19:30:00+00:00'),
        );

        $enricher = new DaypartEnricher($resolver);
        $result   = $enricher->extract($media->getPath(), $media);

        $features = $result->getFeatures();
        self::assertSame('evening', $features['calendar']['daypart'] ?? null);
    }
}
