<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Clusterer;

use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Service\Clusterer\TravelWaypointAnnotator;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_map;

#[CoversClass(TravelWaypointAnnotator::class)]
final class TravelWaypointAnnotatorTest extends TestCase
{
    #[Test]
    public function aggregatesWaypointsAndEvents(): void
    {
        $annotator = new TravelWaypointAnnotator();

        $berlin = (new Location('osm', 'berlin-1', 'Berlin, Deutschland', 52.520008, 13.404954, 'cell-berlin'))
            ->setCity('berlin')
            ->setCountry('Deutschland')
            ->setCountryCode('de')
            ->setState('Berlin')
            ->setPois([
                ['tags' => ['event' => 'yes']],
            ]);

        $hamburg = (new Location('osm', 'hamburg-1', 'Hamburg, Deutschland', 53.551086, 9.993682, 'cell-hamburg'))
            ->setCity('Hamburg')
            ->setCountry('Deutschland')
            ->setCountryCode('DE')
            ->setState('Hamburg')
            ->setPois([
                ['tags' => ['festival' => 'yes']],
            ]);

        $munich = (new Location('osm', 'munich-1', 'München, Deutschland', 48.137154, 11.576124, 'cell-munich'))
            ->setCity('München')
            ->setCountry('Deutschland')
            ->setCountryCode('DE')
            ->setState('Bayern')
            ->setPois([
                ['tags' => ['amenity' => 'theatre']],
            ]);

        $media = [
            $this->makeMedia(1, '/berlin-1.jpg', '2024-05-01 10:00:00 Europe/Berlin', location: $berlin),
            $this->makeMedia(2, '/berlin-2.jpg', '2024-05-02 09:00:00 Europe/Berlin', location: $berlin),
            $this->makeMedia(3, '/hamburg.jpg', '2024-04-29 12:00:00 Europe/Berlin', location: $hamburg),
            $this->makeMedia(4, '/munich.jpg', '2024-04-30 18:00:00 Europe/Berlin', location: $munich),
        ];

        $result = $annotator->annotate($media);

        self::assertCount(3, $result['waypoints']);
        self::assertSame('Berlin', $result['waypoints'][0]['label']);
        self::assertSame(2, $result['waypoints'][0]['count']);
        self::assertSame('Hamburg', $result['waypoints'][1]['label']);
        self::assertSame('München', $result['waypoints'][2]['label']);

        $labels = array_map(static fn (array $entry): string => $entry['label'], $result['events']);
        self::assertSame(['Event', 'Festival', 'Theater'], $labels);
    }

    #[Test]
    public function returnsEmptyListsWhenNoLocationsArePresent(): void
    {
        $annotator = new TravelWaypointAnnotator();

        $media = [
            $this->makeMedia(10, '/no-location.jpg', '2024-01-01 08:00:00 Europe/Berlin'),
        ];

        $result = $annotator->annotate($media);

        self::assertSame([], $result['waypoints']);
        self::assertSame([], $result['events']);
    }
}
