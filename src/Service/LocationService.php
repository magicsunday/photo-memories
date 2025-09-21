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
use RuntimeException;

class LocationService
{
    private readonly string $cacheDir;

    private PDO $pdo;

    public function __construct(?string $customCacheDir = null)
    {
        // Determine cache directory
        $this->cacheDir = $customCacheDir
            ?? getenv('MEMORIES_CACHE_DIR')
            ?: sys_get_temp_dir() . '/memories-cache';

        $this->initializeCacheDir();
        $this->initializeDatabase();
    }

    /**
     * Ensure cache directory exists and is writable.
     */
    private function initializeCacheDir(): void
    {
        if (!is_dir($this->cacheDir) && (!mkdir($this->cacheDir, 0777, true) && !is_dir($this->cacheDir))) {
            throw new RuntimeException('Failed to create cache directory: ' . $this->cacheDir);
        }

        if (!is_writable($this->cacheDir)) {
            throw new RuntimeException('Cache directory is not writable: ' . $this->cacheDir);
        }
    }

    /**
     * Initialize SQLite database in cache directory.
     */
    private function initializeDatabase(): void
    {
        $dbFile = $this->cacheDir . '/location-cache.sqlite';

        $this->pdo = new PDO('sqlite:' . $dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create schema if missing
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS geocache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query TEXT UNIQUE NOT NULL,
                result TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )
        ');
    }

    /**
     * Save geocoding result to cache.
     */
    public function cacheResult(string $query, string $result): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO geocache (query, result, created_at)
            VALUES (:query, :result, :created_at)
            ON CONFLICT(query) DO UPDATE SET
                result = excluded.result,
                created_at = excluded.created_at
        ');

        $stmt->execute([
            ':query'      => $query,
            ':result'     => $result,
            ':created_at' => time(),
        ]);
    }

    /**
     * Load cached result for a query.
     */
    public function getCachedResult(string $query): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT result FROM geocache WHERE query = :query
        ');
        $stmt->execute([':query' => $query]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['result'] ?? null;
    }
}
