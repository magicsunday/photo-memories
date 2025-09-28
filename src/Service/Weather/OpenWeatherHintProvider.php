<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Weather;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Entity\WeatherObservation;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Weather hint provider backed by OpenWeather (or compatible) HTTP APIs.
 *
 * The provider fetches the hourly weather observation matching a media item's
 * timestamp/coordinates and condenses the response into the internal hint
 * structure used by the clustering heuristics.
 */
final class OpenWeatherHintProvider implements WeatherHintProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly WeatherObservationStorageInterface $storage,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $cacheTtl,
        private readonly int $maxPastHours = 0,
        private readonly string $units = 'metric',
        private readonly string $source = WeatherObservation::DEFAULT_SOURCE
    ) {
    }

    public function getHint(Media $media): ?array
    {
        $takenAt = $media->getTakenAt();
        $lat     = $media->getGpsLat();
        $lon     = $media->getGpsLon();

        if (!$takenAt instanceof DateTimeImmutable || $lat === null || $lon === null) {
            return null;
        }

        if ($this->apiKey === '') {
            return null;
        }

        $timestamp = $takenAt->getTimestamp();

        if ($this->maxPastHours > 0) {
            $earliest = time() - ($this->maxPastHours * 3600);
            if ($timestamp < $earliest) {
                return null;
            }
        }

        $stored = $this->storage->findHint($lat, $lon, $timestamp);
        if ($stored !== null) {
            return $stored;
        }

        $cacheKey = $this->buildCacheKey($lat, $lon, $timestamp);

        return $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($lat, $lon, $timestamp): ?array {
                if ($this->cacheTtl > 0) {
                    $item->expiresAfter($this->cacheTtl);
                }

                $hint = $this->fetchHint($lat, $lon, $timestamp);

                if ($hint !== null) {
                    $this->storage->storeHint($lat, $lon, $timestamp, $hint, $this->source);
                }

                return $hint;
            }
        );
    }

    private function fetchHint(float $lat, float $lon, int $timestamp): ?array
    {
        try {
            $response = $this->http->request(
                'GET',
                $this->baseUrl,
                [
                    'query' => [
                        'lat'    => $lat,
                        'lon'    => $lon,
                        'dt'     => $timestamp,
                        'appid'  => $this->apiKey,
                        'units'  => $this->units,
                        'exclude'=> 'minutely,daily,alerts',
                    ],
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $payload = $response->toArray(false);
        } catch (
            ClientExceptionInterface |
            DecodingExceptionInterface |
            RedirectionExceptionInterface |
            ServerExceptionInterface |
            TransportExceptionInterface $exception
        ) {
            return null;
        }

        return $this->extractHint($payload, $timestamp);
    }

    /**
     * @param array<mixed> $payload
     * @return array{rain_prob: float, precip_mm?: float}|null
     */
    private function extractHint(array $payload, int $timestamp): ?array
    {
        if (isset($payload['hourly'])) {
            $hourly = $payload['hourly'];

            if (\is_array($hourly)) {
                if ($hourly !== [] && \array_is_list($hourly)) {
                    $entry = $this->findBestMatchingEntry($hourly, $timestamp);
                    if ($entry !== null) {
                        return $this->normaliseEntry($entry);
                    }
                } elseif (isset($hourly['time']) && \is_array($hourly['time'])) {
                    $entry = $this->convertHourlyMatrixToEntry($hourly, $timestamp);
                    if ($entry !== null) {
                        return $this->normaliseEntry($entry);
                    }
                }
            }
        }

        if (isset($payload['current']) && \is_array($payload['current'])) {
            $hint = $this->normaliseEntry($payload['current']);
            if ($hint !== null) {
                return $hint;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, mixed>|null
     */
    private function findBestMatchingEntry(array $entries, int $timestamp): ?array
    {
        $best     = null;
        $bestDiff = null;

        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\array_key_exists('dt', $entry)) {
                continue;
            }

            $dt = (int) $entry['dt'];
            $diff = \abs($dt - $timestamp);

            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $best     = $entry;
            }
        }

        if ($best === null) {
            return null;
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $matrix
     * @return array<string, mixed>|null
     */
    private function convertHourlyMatrixToEntry(array $matrix, int $timestamp): ?array
    {
        $times = $matrix['time'];
        if (!\is_array($times) || $times === []) {
            return null;
        }

        $bestIdx  = null;
        $bestDiff = null;

        foreach ($times as $idx => $timeString) {
            if (!\is_string($timeString)) {
                continue;
            }

            $dt = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $timeString);
            if (!$dt instanceof DateTimeImmutable) {
                $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $timeString);
            }
            if (!$dt instanceof DateTimeImmutable) {
                continue;
            }

            $diff = \abs($dt->getTimestamp() - $timestamp);
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $bestIdx  = $idx;
            }
        }

        if ($bestIdx === null) {
            return null;
        }

        $entry = ['dt' => $timestamp];

        foreach ($matrix as $key => $values) {
            if (!\is_array($values) || !\array_key_exists($bestIdx, $values)) {
                continue;
            }
            $entry[$key] = $values[$bestIdx];
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{rain_prob: float, precip_mm?: float}|null
     */
    private function normaliseEntry(array $entry): ?array
    {
        $hint = [];

        $rainProb = null;
        if (isset($entry['pop'])) {
            $rainProb = $this->clamp01((float) $entry['pop']);
        } elseif (isset($entry['precipitation_probability'])) {
            $value = (float) $entry['precipitation_probability'];
            if ($value > 1.0) {
                $value /= 100.0;
            }
            $rainProb = $this->clamp01($value);
        } elseif (isset($entry['rain_probability'])) {
            $value    = (float) $entry['rain_probability'];
            if ($value > 1.0) {
                $value /= 100.0;
            }
            $rainProb = $this->clamp01($value);
        }

        if (isset($entry['rain'])) {
            if (\is_array($entry['rain']) && isset($entry['rain']['1h'])) {
                $hint['precip_mm'] = \max(0.0, (float) $entry['rain']['1h']);
            } elseif (\is_numeric($entry['rain'])) {
                $hint['precip_mm'] = \max(0.0, (float) $entry['rain']);
            }
        }

        if (!isset($hint['precip_mm']) && isset($entry['precipitation'])) {
            $hint['precip_mm'] = \max(0.0, (float) $entry['precipitation']);
        }

        if (isset($entry['clouds'])) {
            $hint['cloud_cover'] = $this->clamp01(((float) $entry['clouds']) / 100.0);
        } elseif (isset($entry['cloud_cover'])) {
            $value = (float) $entry['cloud_cover'];
            $hint['cloud_cover'] = $this->clamp01($value > 1.0 ? $value / 100.0 : $value);
        } elseif (isset($entry['cloudcover'])) {
            $value = (float) $entry['cloudcover'];
            $hint['cloud_cover'] = $this->clamp01($value > 1.0 ? $value / 100.0 : $value);
        }

        if (isset($entry['sun_prob'])) {
            $hint['sun_prob'] = $this->clamp01((float) $entry['sun_prob']);
        } elseif (isset($hint['cloud_cover'])) {
            $hint['sun_prob'] = $this->clamp01(1.0 - $hint['cloud_cover']);
        }

        if (isset($entry['temp'])) {
            $hint['temp_c'] = (float) $entry['temp'];
        } elseif (isset($entry['temperature'])) {
            $hint['temp_c'] = (float) $entry['temperature'];
        } elseif (isset($entry['temperature_2m'])) {
            $hint['temp_c'] = (float) $entry['temperature_2m'];
        }

        if (isset($entry['weather']) && \is_array($entry['weather']) && isset($entry['weather'][0]['description'])) {
            $hint['summary'] = (string) $entry['weather'][0]['description'];
        }

        if ($rainProb === null) {
            if (isset($hint['cloud_cover'])) {
                $rainProb = $hint['cloud_cover'];
            } else {
                $rainProb = 0.0;
            }
        }

        $hint['rain_prob'] = $this->clamp01($rainProb);

        return $hint === [] ? null : $hint;
    }

    private function buildCacheKey(float $lat, float $lon, int $timestamp): string
    {
        $bucket = (string) \intdiv($timestamp, 3600);
        $hash   = \sha1(\sprintf('%s|%.3f|%.3f', $bucket, $lat, $lon));

        return 'weather_' . $hash;
    }

    private function clamp01(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }
}

