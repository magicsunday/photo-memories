<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Weather;

use DateTimeImmutable;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Entity\WeatherObservation;
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
final readonly class OpenWeatherHintProvider implements WeatherHintProviderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private WeatherObservationStorageInterface $storage,
        private string $baseUrl,
        private string $apiKey,
        private int $maxPastHours = 0,
        private string $units = 'metric',
        private string $source = WeatherObservation::DEFAULT_SOURCE
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

        $hint = $this->fetchHint($lat, $lon, $timestamp);

        if ($hint !== null) {
            $this->storage->storeHint($lat, $lon, $timestamp, $hint, $this->source);
        }

        return $hint;
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
            TransportExceptionInterface
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

            if (\is_array($hourly) && $hourly !== [] && \array_is_list($hourly)) {
                $entry = $this->findBestMatchingEntry($hourly, $timestamp);
                if ($entry !== null) {
                    return $this->normaliseEntry($entry);
                }
            }
        }

        if (isset($payload['current']) && \is_array($payload['current'])) {
            return $this->normaliseEntry($payload['current']);
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
            if (!\is_array($entry)) {
                continue;
            }

            if (!\array_key_exists('dt', $entry)) {
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
     * @param array<string, mixed> $entry
     * @return array<'rain_prob', float>
     */
    private function normaliseEntry(array $entry): array
    {
        $hint = [];

        $rainProb = null;
        if (isset($entry['pop'])) {
            $rainProb = $this->clamp01((float) $entry['pop']);
        }

        if (isset($entry['rain'])) {
            if (\is_array($entry['rain']) && isset($entry['rain']['1h'])) {
                $hint['precip_mm'] = \max(0.0, (float) $entry['rain']['1h']);
            } elseif (\is_numeric($entry['rain'])) {
                $hint['precip_mm'] = \max(0.0, (float) $entry['rain']);
            }
        }

        if (isset($entry['clouds'])) {
            $hint['cloud_cover'] = $this->clamp01(((float) $entry['clouds']) / 100.0);
        }

        if (isset($hint['cloud_cover'])) {
            $hint['sun_prob'] = $this->clamp01(1.0 - $hint['cloud_cover']);
        }

        if (isset($entry['temp'])) {
            $hint['temp_c'] = (float) $entry['temp'];
        }

        if (isset($entry['weather']) && \is_array($entry['weather']) && isset($entry['weather'][0]['description'])) {
            $hint['summary'] = (string) $entry['weather'][0]['description'];
        }

        if ($rainProb === null) {
            $rainProb = $hint['cloud_cover'] ?? 0.0;
        }

        $hint['rain_prob'] = $this->clamp01($rainProb);

        return $hint === [] ? null : $hint;
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

