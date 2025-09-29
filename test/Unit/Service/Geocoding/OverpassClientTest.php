<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Service\Geocoding;

use MagicSunday\Memories\Service\Geocoding\OverpassClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class OverpassClientTest extends TestCase
{
    #[Test]
    public function buildsQueryUsingTagCombinations(): void
    {
        $response = new StaticJsonResponse(200, [
            'elements' => [
                [
                    'type' => 'node',
                    'id'   => 1,
                    'lat'  => 1.23,
                    'lon'  => 3.21,
                    'tags' => [
                        'name'    => 'Example Park',
                        'tourism' => 'theme_park',
                    ],
                ],
            ],
        ]);

        $client = new RecordingHttpClient($response);

        $overpass = new OverpassClient(
            http: $client,
            additionalAllowedTags: [
                [
                    'tourism' => ['attraction'],
                    'historic' => ['castle', 'ruins'],
                ],
                [
                    'tourism' => ['theme_park'],
                ],
            ],
        );

        $pois = $overpass->fetchPois(1.23, 3.21, 250, null);

        self::assertNotSame('', $client->lastQuery);
        self::assertStringContainsString('nwr(around:250,1.2300000,3.2100000)["tourism"="theme_park"]', $client->lastQuery);
        self::assertStringContainsString('["tourism"="attraction"]["historic"~"^(castle|ruins)$"]', $client->lastQuery);

        self::assertCount(1, $pois);
        $firstPoi = $pois[0];

        self::assertSame('tourism', $firstPoi['categoryKey']);
        self::assertSame('theme_park', $firstPoi['categoryValue']);
    }
}

final class RecordingHttpClient implements HttpClientInterface
{
    public string $lastQuery = '';

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $data = $options['body']['data'] ?? null;
        $this->lastQuery = is_string($data) ? $data : '';

        return $this->response;
    }

    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}

final readonly class StaticJsonResponse implements ResponseInterface
{
    public function __construct(
        private int $statusCode,
        private array $payload,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return '';
    }

    public function toArray(bool $throw = true): array
    {
        return $this->payload;
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}
