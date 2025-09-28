<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Weather;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\WeatherObservation;
use MagicSunday\Memories\Service\Weather\OpenWeatherHintProvider;
use MagicSunday\Memories\Service\Weather\WeatherObservationStorageInterface;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenWeatherHintProviderTest extends TestCase
{
    #[Test]
    public function fetchesAndNormalisesOpenWeatherPayload(): void
    {
        $timestamp = (new DateTimeImmutable('2024-06-01T12:00:00Z'))->getTimestamp();

        $captured = [];
        $client   = new MockHttpClient(
            function (string $method, string $url, array $options) use ($timestamp, &$captured): MockResponse {
                $captured = compact('method', 'url', 'options');

                return new MockResponse(
                    \json_encode([
                        'hourly' => [
                            [
                                'dt'     => $timestamp,
                                'pop'    => 0.75,
                                'rain'   => ['1h' => 1.2],
                                'clouds' => 20,
                                'weather'=> [['description' => 'light rain']],
                            ],
                        ],
                    ]),
                    ['http_code' => 200]
                );
            }
        );

        $storage  = new InMemoryObservationStorage();
        $provider = new OpenWeatherHintProvider(
            $client,
            $storage,
            'https://api.test/weather',
            'api-key',
            0
        );

        $media = $this->makeMediaFixture(
            id: 1,
            filename: 'sample.jpg',
            takenAt: '2024-06-01T12:00:00Z',
            lat: 48.1,
            lon: 11.5,
        );

        $hint = $provider->getHint($media);

        self::assertNotNull($hint);
        self::assertEqualsWithDelta(0.75, $hint['rain_prob'], 1e-6);
        self::assertEqualsWithDelta(1.2, $hint['precip_mm'], 1e-6);
        self::assertEqualsWithDelta(0.2, $hint['cloud_cover'], 1e-6);
        self::assertEqualsWithDelta(0.8, $hint['sun_prob'], 1e-6);
        self::assertSame('light rain', $hint['summary']);

        self::assertSame('GET', $captured['method']);
        self::assertStringStartsWith('https://api.test/weather', $captured['url']);
        self::assertSame('api-key', $captured['options']['query']['appid']);
        self::assertSame(48.1, $captured['options']['query']['lat']);
        self::assertSame(11.5, $captured['options']['query']['lon']);
        self::assertSame($timestamp, $captured['options']['query']['dt']);
        self::assertTrue($storage->hasObservation(48.1, 11.5, $timestamp));
    }

    #[Test]
    public function reusesStoredObservationForSubsequentCalls(): void
    {
        $timestamp = (new DateTimeImmutable('2024-06-01T12:00:00Z'))->getTimestamp();
        $requests  = 0;

        $client = new MockHttpClient(
            function () use ($timestamp, &$requests): MockResponse {
                $requests++;

                return new MockResponse(
                    \json_encode([
                        'hourly' => [
                            [
                                'dt'   => $timestamp,
                                'pop'  => 0.6,
                                'rain' => ['1h' => 0.4],
                            ],
                        ],
                    ]),
                    ['http_code' => 200]
                );
            }
        );

        $storage  = new InMemoryObservationStorage();
        $provider = new OpenWeatherHintProvider(
            $client,
            $storage,
            'https://api.test/weather',
            'api-key',
            0
        );

        $media = $this->makeMediaFixture(
            id: 10,
            filename: 'cached.jpg',
            takenAt: '2024-06-01T12:10:00Z',
            lat: 48.1,
            lon: 11.5,
        );

        $first  = $provider->getHint($media);
        $second = $provider->getHint($media);

        self::assertSame(1, $requests);
        self::assertSame($first, $second);
    }

    #[Test]
    public function skipsWhenCoordinatesMissing(): void
    {
        $requests = 0;
        $client   = new MockHttpClient(
            function () use (&$requests): MockResponse {
                $requests++;

                return new MockResponse('[]', ['http_code' => 200]);
            }
        );

        $storage  = new InMemoryObservationStorage();
        $provider = new OpenWeatherHintProvider(
            $client,
            $storage,
            'https://api.test/weather',
            'api-key',
            0
        );

        $media = $this->makeMediaFixture(
            id: 30,
            filename: 'no-coord.jpg',
            takenAt: '2024-06-01T12:00:00Z',
        );

        self::assertNull($provider->getHint($media));
        self::assertSame(0, $requests);
    }
}

/**
 * @internal test helper
 */
final class InMemoryObservationStorage implements WeatherObservationStorageInterface
{
    /** @var array<string, array{hint: array<string, mixed>, source: string}> */
    private array $storage = [];

    public function findHint(float $lat, float $lon, int $timestamp): ?array
    {
        $key = WeatherObservation::lookupHashFromRaw($lat, $lon, $timestamp);

        return $this->storage[$key]['hint'] ?? null;
    }

    public function hasObservation(float $lat, float $lon, int $timestamp): bool
    {
        $key = WeatherObservation::lookupHashFromRaw($lat, $lon, $timestamp);

        return \array_key_exists($key, $this->storage);
    }

    public function storeHint(float $lat, float $lon, int $timestamp, array $hint, string $source): void
    {
        $key = WeatherObservation::lookupHashFromRaw($lat, $lon, $timestamp);

        $this->storage[$key] = [
            'hint'   => $hint,
            'source' => $source,
        ];
    }
}
