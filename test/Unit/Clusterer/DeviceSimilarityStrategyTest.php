<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Clusterer;

use DateTimeImmutable;
use DateTimeZone;
use MagicSunday\Memories\Clusterer\ClusterDraft;
use MagicSunday\Memories\Clusterer\DeviceSimilarityStrategy;
use MagicSunday\Memories\Entity\Enum\ContentKind;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Test\TestCase;
use MagicSunday\Memories\Utility\LocationHelper;
use PHPUnit\Framework\Attributes\Test;

final class DeviceSimilarityStrategyTest extends TestCase
{
    #[Test]
    public function groupsMediaByDeviceDateAndLocation(): void
    {
        $strategy = new DeviceSimilarityStrategy(LocationHelper::createDefault(), minItemsPerGroup: 3);

        $berlin = $this->makeLocation(
            providerPlaceId: 'berlin-001',
            displayName: 'Berlin',
            lat: 52.5200,
            lon: 13.4050,
            city: 'Berlin',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(301, '2023-05-01 09:00:00', 'Canon EOS R5', $berlin, 52.5200, 13.4050, 'RF24-70mm F2.8', ContentKind::PHOTO),
            $this->createMedia(302, '2023-05-01 10:30:00', 'Canon EOS R5', $berlin, 52.5203, 13.4052, 'RF24-70mm F2.8', ContentKind::PHOTO),
            $this->createMedia(303, '2023-05-01 11:45:00', 'Canon EOS R5', $berlin, 52.5205, 13.4054, 'RF24-70mm F2.8', ContentKind::PHOTO),
            // Different day, should not form a cluster because below minItems
            $this->createMedia(304, '2023-05-02 09:15:00', 'Canon EOS R5', $berlin, 52.5206, 13.4056),
            $this->createMedia(305, '2023-05-02 12:00:00', 'Canon EOS R5', $berlin, 52.5207, 13.4057),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);
        $cluster = $clusters[0];

        self::assertInstanceOf(ClusterDraft::class, $cluster);
        self::assertSame('device_similarity', $cluster->getAlgorithm());
        self::assertSame([301, 302, 303], $cluster->getMembers());

        $params = $cluster->getParams();
        self::assertSame('Canon EOS R5', $params['device']);
        self::assertSame('Berlin', $params['place']);
        self::assertSame('RF24-70mm F2.8', $params['lensModel']);
        self::assertSame(ContentKind::PHOTO->value, $params['contentKind']);

        $expectedRange = [
            'from' => (new DateTimeImmutable('2023-05-01 09:00:00', new DateTimeZone('UTC')))->getTimestamp(),
            'to'   => (new DateTimeImmutable('2023-05-01 11:45:00', new DateTimeZone('UTC')))->getTimestamp(),
        ];
        self::assertSame($expectedRange, $params['time_range']);

        $centroid = $cluster->getCentroid();
        self::assertEqualsWithDelta(52.5202666667, $centroid['lat'], 0.0001);
        self::assertEqualsWithDelta(13.4052, $centroid['lon'], 0.0001);
    }

    #[Test]
    public function aggregatesQualityMetricsIntoParams(): void
    {
        $strategy = new DeviceSimilarityStrategy(LocationHelper::createDefault(), minItemsPerGroup: 2);

        $location = $this->makeLocation(
            providerPlaceId: 'quality-berlin',
            displayName: 'Berlin',
            lat: 52.52,
            lon: 13.405,
            city: 'Berlin',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(
                901,
                '2023-08-01 08:00:00',
                'Canon EOS R6',
                $location,
                52.5201,
                13.4051,
                width: 4000,
                height: 3000,
                sharpness: 0.8,
                iso: 100,
                brightness: 0.55,
                contrast: 0.5,
                entropy: 0.6,
                colorfulness: 0.7,
            ),
            $this->createMedia(
                902,
                '2023-08-01 08:05:00',
                'Canon EOS R6',
                $location,
                52.5202,
                13.4052,
                width: 4000,
                height: 3000,
                sharpness: 0.8,
                iso: 100,
                brightness: 0.55,
                contrast: 0.5,
                entropy: 0.6,
                colorfulness: 0.7,
            ),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(1, $clusters);

        $params = $clusters[0]->getParams();

        self::assertArrayHasKey('quality_avg', $params);
        self::assertArrayHasKey('quality_resolution', $params);
        self::assertArrayHasKey('quality_sharpness', $params);
        self::assertArrayHasKey('quality_iso', $params);
        self::assertArrayHasKey('aesthetics_score', $params);

        self::assertEqualsWithDelta(0.9014, $params['quality_avg'], 0.0005);
        self::assertEqualsWithDelta(1.0, $params['quality_resolution'], 0.0005);
        self::assertEqualsWithDelta(0.8, $params['quality_sharpness'], 0.0005);
        self::assertEqualsWithDelta(0.8571, $params['quality_iso'], 0.0005);
        self::assertEqualsWithDelta(0.725, $params['aesthetics_score'], 0.0005);
    }

    #[Test]
    public function returnsEmptyWhenGroupsDoNotReachMinimum(): void
    {
        $strategy = new DeviceSimilarityStrategy(LocationHelper::createDefault(), minItemsPerGroup: 4);

        $location = $this->makeLocation(
            providerPlaceId: 'munich-001',
            displayName: 'Munich',
            lat: 48.1371,
            lon: 11.5753,
            city: 'Munich',
            country: 'Germany',
        );

        $mediaItems = [
            $this->createMedia(401, '2023-06-10 08:00:00', 'iPhone 14 Pro', $location, 48.1371, 11.5753),
            $this->createMedia(402, '2023-06-10 08:05:00', 'iPhone 14 Pro', $location, 48.1372, 11.5754),
            $this->createMedia(403, '2023-06-10 08:10:00', 'iPhone 14 Pro', $location, 48.1373, 11.5755),
        ];

        self::assertSame([], $strategy->cluster($mediaItems));
    }

    #[Test]
    public function separatesMediaByOwnerAndSerial(): void
    {
        $strategy = new DeviceSimilarityStrategy(LocationHelper::createDefault(), minItemsPerGroup: 2);

        $paris = $this->makeLocation(
            providerPlaceId: 'paris-001',
            displayName: 'Paris',
            lat: 48.8566,
            lon: 2.3522,
            city: 'Paris',
            country: 'France',
        );

        $mediaItems = [
            $this->createMedia(601, '2023-07-10 08:00:00', 'Fujifilm X-T5', $paris, 48.8566, 2.3522, cameraOwner: 'Alice', cameraMake: 'Fujifilm', cameraSerial: 'SN-123'),
            $this->createMedia(602, '2023-07-10 08:05:00', 'Fujifilm X-T5', $paris, 48.8567, 2.3523, cameraOwner: 'Alice', cameraMake: 'Fujifilm', cameraSerial: 'SN-123'),
            $this->createMedia(603, '2023-07-10 09:00:00', 'Fujifilm X-T5', $paris, 48.8568, 2.3524, cameraOwner: 'Alice', cameraMake: 'Fujifilm', cameraSerial: 'SN-456'),
            $this->createMedia(604, '2023-07-10 09:05:00', 'Fujifilm X-T5', $paris, 48.8569, 2.3525, cameraOwner: 'Alice', cameraMake: 'Fujifilm', cameraSerial: 'SN-456'),
            $this->createMedia(605, '2023-07-10 10:00:00', 'Fujifilm X-T5', $paris, 48.8570, 2.3526, cameraOwner: 'Bob', cameraMake: 'Fujifilm', cameraSerial: 'SN-123'),
            $this->createMedia(606, '2023-07-10 10:05:00', 'Fujifilm X-T5', $paris, 48.8571, 2.3527, cameraOwner: 'Bob', cameraMake: 'Fujifilm', cameraSerial: 'SN-123'),
        ];

        $clusters = $strategy->cluster($mediaItems);

        self::assertCount(3, $clusters);

        $membersByDevice = [];
        foreach ($clusters as $cluster) {
            $params                             = $cluster->getParams();
            $membersByDevice[$params['device']] = $cluster->getMembers();
        }

        $aliceFirstBody  = 'Fujifilm X-T5 – Besitzer: Alice, Seriennummer: SN-123';
        $aliceSecondBody = 'Fujifilm X-T5 – Besitzer: Alice, Seriennummer: SN-456';
        $bobBody         = 'Fujifilm X-T5 – Besitzer: Bob, Seriennummer: SN-123';

        self::assertArrayHasKey($aliceFirstBody, $membersByDevice);
        self::assertArrayHasKey($aliceSecondBody, $membersByDevice);
        self::assertArrayHasKey($bobBody, $membersByDevice);

        self::assertSame([601, 602], $membersByDevice[$aliceFirstBody]);
        self::assertSame([603, 604], $membersByDevice[$aliceSecondBody]);
        self::assertSame([605, 606], $membersByDevice[$bobBody]);
    }

    private function createMedia(
        int $id,
        string $takenAt,
        ?string $camera,
        Location $location,
        float $lat,
        float $lon,
        ?string $lensModel = null,
        ?ContentKind $contentKind = null,
        ?string $cameraOwner = null,
        ?string $cameraMake = null,
        ?string $cameraSerial = null,
        ?int $width = null,
        ?int $height = null,
        ?float $sharpness = null,
        ?int $iso = null,
        ?float $brightness = null,
        ?float $contrast = null,
        ?float $entropy = null,
        ?float $colorfulness = null,
    ): Media {
        return $this->makeMediaFixture(
            id: $id,
            filename: sprintf('device-%d.jpg', $id),
            takenAt: $takenAt,
            lat: $lat,
            lon: $lon,
            location: $location,
            configure: static function (Media $media) use (
                $camera,
                $lensModel,
                $contentKind,
                $cameraOwner,
                $cameraMake,
                $cameraSerial,
                $width,
                $height,
                $sharpness,
                $iso,
                $brightness,
                $contrast,
                $entropy,
                $colorfulness,
            ): void {
                $media->setCameraModel($camera);
                $media->setLensModel($lensModel);
                $media->setContentKind($contentKind);
                if ($cameraMake !== null) {
                    $media->setCameraMake($cameraMake);
                }

                if ($cameraOwner !== null) {
                    $media->setCameraOwner($cameraOwner);
                }

                if ($cameraSerial !== null) {
                    $media->setCameraBodySerial($cameraSerial);
                }

                if ($width !== null) {
                    $media->setWidth($width);
                }

                if ($height !== null) {
                    $media->setHeight($height);
                }

                if ($sharpness !== null) {
                    $media->setSharpness($sharpness);
                }

                if ($iso !== null) {
                    $media->setIso($iso);
                }

                if ($brightness !== null) {
                    $media->setBrightness($brightness);
                }

                if ($contrast !== null) {
                    $media->setContrast($contrast);
                }

                if ($entropy !== null) {
                    $media->setEntropy($entropy);
                }

                if ($colorfulness !== null) {
                    $media->setColorfulness($colorfulness);
                }
            },
        );
    }
}
