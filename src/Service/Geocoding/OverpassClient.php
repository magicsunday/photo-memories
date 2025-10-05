<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function max;
use function trim;

/**
 * Minimal Overpass API client fetching nearby Points of Interest.
 */
final class OverpassClient
{
    private bool $lastUsedNetwork = false;

    /**
     * @param float $httpTimeout timeout in seconds for the HTTP request (symfony client option)
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly OverpassQueryBuilderInterface $queryBuilder,
        private readonly OverpassResponseParserInterface $responseParser,
        private readonly string $baseUrl = 'https://overpass-api.de/api',
        private readonly string $userAgent = 'Rueckblick/1.0',
        private readonly ?string $contactEmail = null,
        private readonly float $httpTimeout = 25.0,
    ) {
    }

    /**
     * Fetches POIs around given coordinates.
     *
     * @return list<array<string,mixed>>
     */
    public function fetchPois(float $lat, float $lon, int $radiusMeters, ?int $limit): array
    {
        $this->lastUsedNetwork = false;

        if ($radiusMeters <= 0) {
            return [];
        }

        $queryLimit = $limit !== null ? max(1, $limit) : null;
        $query      = $this->queryBuilder->build($lat, $lon, $radiusMeters, $queryLimit);

        try {
            $this->lastUsedNetwork = true;
            $response              = $this->http->request('POST', $this->baseUrl . '/interpreter', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                    'User-Agent'   => $this->userAgentWithContact(),
                ],
                'body' => [
                    'data' => $query,
                ],
                'timeout' => $this->httpTimeout,
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            /** @var array<string,mixed> $payload */
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|ClientExceptionInterface|RedirectionExceptionInterface|ServerExceptionInterface|DecodingExceptionInterface) {
            return [];
        }

        return $this->responseParser->parse($payload, $lat, $lon, $queryLimit);
    }

    public function consumeLastUsedNetwork(): bool
    {
        $used                  = $this->lastUsedNetwork;
        $this->lastUsedNetwork = false;

        return $used;
    }

    private function userAgentWithContact(): string
    {
        $email = $this->contactEmail;
        if ($email === null) {
            return $this->userAgent;
        }

        $trimmed = trim($email);
        if ($trimmed === '') {
            return $this->userAgent;
        }

        return $this->userAgent . ' (' . $trimmed . ')';
    }
}
