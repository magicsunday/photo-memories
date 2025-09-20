<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service;

use PDO;
use PDOException;

use function dirname;
use function is_array;
use function sprintf;

class LocationService implements LocationServiceInterface
{
    private PDO $pdo;
    private string $endpoint = 'https://nominatim.openstreetmap.org/reverse';

    /**
     * Constructor.
     *
     * @param string $dbFile
     */
    public function __construct(string $dbFile = __DIR__ . '/../../cache/locations.sqlite')
    {
        $dir = dirname($dbFile);

        if (!is_dir($dir)
            && !mkdir($dir, 0777, true)
            && !is_dir($dir)
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $dir
                )
            );
        }

        $this->pdo = new PDO('sqlite:' . $dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->initSchema();
    }

    private function initSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS locations (
                lat_rounded REAL NOT NULL,
                lon_rounded REAL NOT NULL,
                place TEXT NOT NULL,
                PRIMARY KEY (lat_rounded, lon_rounded)
            )'
        );
    }

    /**
     * Liefert einen Ortstitel (z. B. "Rom, Italien") für Koordinaten.
     */
    public function reverseGeocode(float $lat, float $lon): ?string
    {
        $latRounded = round($lat, 3);
        $lonRounded = round($lon, 3);

        // 1. Versuch: Cache-Lookup
        $stmt = $this->pdo->prepare('SELECT place FROM locations WHERE lat_rounded = :lat AND lon_rounded = :lon');
        $stmt->execute([':lat' => $latRounded, ':lon' => $lonRounded]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row['place'];
        }

        // 2. API-Aufruf
        $placeName = $this->queryNominatim($lat, $lon);

        if ($placeName) {
            $this->insertCache($latRounded, $lonRounded, $placeName);
        }

        return $placeName;
    }

    private function insertCache(float $lat, float $lon, string $place): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO locations (lat_rounded, lon_rounded, place) VALUES (:lat, :lon, :place)'
            );
            $stmt->execute([':lat' => $lat, ':lon' => $lon, ':place' => $place]);
        } catch (PDOException $e) {
            // Ignorieren, wenn DB Probleme macht
        }
    }

    private function queryNominatim(float $lat, float $lon): ?string
    {
        $url = sprintf(
            '%s?lat=%F&lon=%F&format=jsonv2',
            $this->endpoint,
            $lat,
            $lon
        );

        $opts = [
            'http' => [
                'method'  => 'GET',
                'header'  => [
                    'User-Agent: photo-memories-cli/1.0 (your-email@example.com)',
                ],
                'timeout' => 5,
            ],
        ];

        $context  = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode(
            $response,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($data) || empty($data['address'])) {
            return null;
        }

        $address = $data['address'];
        $city    = $address['city'] ?? $address['town'] ?? $address['village'] ?? null;
        $country = $address['country'] ?? null;

        if ($city && $country) {
            return $city . ', ' . $country;
        }

        if ($country) {
            return $country;
        }

        return null;
    }
}
