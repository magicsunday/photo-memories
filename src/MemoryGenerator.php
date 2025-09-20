<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories;

use DateTimeImmutable;
use MagicSunday\Memories\Model\MediaItem;
use MagicSunday\Memories\Model\Memory;
use MagicSunday\Memories\Service\LocationService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

use function count;
use function function_exists;
use function in_array;
use function sprintf;

/**
 * Class MemoryGenerator.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/MIT
 * @link    https://github.com/magicsunday/photo-memories/
 */
class MemoryGenerator
{
    /**
     * @var LocationService
     */
    private LocationService $locationService;

    /**
     * Constructor.
     */
    public function __construct(?LocationService $locationService = null)
    {
        $this->locationService = $locationService ?? new LocationService();
    }

    /**
     * Scan directory for media files.
     *
     * @return MediaItem[]
     */
    public function scanDirectory(string $dir): array
    {
        $items = [];
        $rii   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($rii as $file) {
            if ($file->isDir()) {
                continue;
            }

            $path = $file->getPathname();
            $ext  = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $type = 'image';
            } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv'], true)) {
                $type = 'video';
            } else {
                continue;
            }

            $meta = [];
            if ($type === 'image') {
                $meta = $this->extractExif($path);
            }

            $createdAt = $meta['createdAt'] ?? $this->guessDate($path);
            $score     = $this->scoreFile($path, $type);

            $item = new MediaItem($path, $type, $createdAt, $score);

            $item->latitude    = $meta['latitude'] ?? null;
            $item->longitude   = $meta['longitude'] ?? null;
            $item->cameraModel = $meta['cameraModel'] ?? null;

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Cluster media by date.
     *
     * @param MediaItem[] $items
     *
     * @return Memory[]
     */
    public function buildMemories(array $items, int $gapDays = 18, float $gapKm = 50.0): array
    {
        if ($items === []) {
            return [];
        }

        usort($items, static fn ($a, $b): int => $a->createdAt <=> $b->createdAt);

        $memories = [];
        $cluster  = [$items[0]];
        $last     = $items[0]->createdAt;
        $lastLat  = $items[0]->latitude;
        $lastLon  = $items[0]->longitude;
        $counter  = count($items);

        for ($i = 1; $i < $counter; ++$i) {
            $item = $items[$i];

            $diffDays = $last->diff($item->createdAt)->days;

            $samePlace = true;
            if ($lastLat !== null && $lastLon !== null && $item->latitude !== null && $item->longitude !== null) {
                $distKm = $this->distanceKm($lastLat, $lastLon, $item->latitude, $item->longitude);
                $samePlace = ($distKm <= $gapKm);
            }

            if ($diffDays <= $gapDays && $samePlace) {
                $cluster[] = $item;
            } else {
                $memories[] = $this->makeMemory($cluster);
                $cluster    = [$item];
            }

            $last    = $item->createdAt;
            $lastLat = $item->latitude;
            $lastLon = $item->longitude;
        }

        $memories[] = $this->makeMemory($cluster);

        return $memories;
    }

    /**
     * Create a Memory object from a cluster of MediaItems.
     *
     * @param MediaItem[] $cluster
     */
    private function makeMemory(array $cluster): Memory
    {
        $start = $cluster[0]->createdAt;
        $end   = end($cluster)->createdAt;
        $title = $this->formatTitle($start, $end);

        // Ortsangabe
        $latitudes  = array_filter(array_map(fn($i) => $i->latitude, $cluster));
        $longitudes = array_filter(array_map(fn($i) => $i->longitude, $cluster));

        if ($latitudes !== [] && $longitudes !== []) {
            $avgLat = array_sum($latitudes) / count($latitudes);
            $avgLon = array_sum($longitudes) / count($longitudes);

            $placeName = $this->locationService->reverseGeocode($avgLat, $avgLon);

            if ($placeName) {
                $title = $placeName . ' – ' . $title;
            }
        }

        return new Memory($title, $start, $end, $cluster);
    }

    private function formatTitle(DateTimeImmutable $start, DateTimeImmutable $end): string
    {
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('d. M Y');
        }

        return $start->format('d. M Y') . ' – ' . $end->format('d. M Y');
    }

    private function guessDate(string $path): DateTimeImmutable
    {
        $mtime = filemtime($path);
        $ts    = $mtime !== false ? $mtime : time();

        return (new DateTimeImmutable())->setTimestamp($ts);
    }

    private function scoreFile(string $path, string $type): float
    {
        // Dummy scoring: random für Demo
        return random_int(60, 95) / 100;
    }

    /**
     * @param string $path
     *
     * @return array<string, DateTimeImmutable|float|string|null>
     */
    private function extractExif(string $path): array
    {
        $meta = [
            'createdAt'   => null,
            'latitude'    => null,
            'longitude'   => null,
            'cameraModel' => null,
        ];

        if (!function_exists('exif_read_data')) {
            return $meta;
        }

        try {
            $exif = @exif_read_data($path, 'ANY_TAG', true);

            if ($exif === false) {
                return $meta;
            }

            // Datum
            if (isset($exif['EXIF']['DateTimeOriginal'])) {
                $meta['createdAt'] = new DateTimeImmutable($exif['EXIF']['DateTimeOriginal']);
            } elseif (isset($exif['IFD0']['DateTime'])) {
                $meta['createdAt'] = new DateTimeImmutable($exif['IFD0']['DateTime']);
            }

            // Kamera
            if (isset($exif['IFD0']['Model'])) {
                $meta['cameraModel'] = trim((string) $exif['IFD0']['Model']);
            }

            // GPS
            if (isset($exif['GPS']['GPSLatitude']) && isset($exif['GPS']['GPSLongitude'])) {
                $lat               = $this->gps2Num($exif['GPS']['GPSLatitude'], $exif['GPS']['GPSLatitudeRef']);
                $lon               = $this->gps2Num($exif['GPS']['GPSLongitude'], $exif['GPS']['GPSLongitudeRef']);
                $meta['latitude']  = $lat;
                $meta['longitude'] = $lon;
            }
        } catch (Throwable) {
            // Ignorieren, wenn kaputte EXIF-Daten
        }

        return $meta;
    }

    /**
     * @param string[] $coordPart
     * @param string   $hemisphere
     *
     * @return float
     */
    private function gps2Num(array $coordPart, string $hemisphere): float
    {
        $degrees = $coordPart !== [] ? $this->fractionToFloat($coordPart[0]) : 0;
        $minutes = count($coordPart) > 1 ? $this->fractionToFloat($coordPart[1]) : 0;
        $seconds = count($coordPart) > 2 ? $this->fractionToFloat($coordPart[2]) : 0;

        $flip = ($hemisphere === 'W' || $hemisphere === 'S') ? -1 : 1;

        return $flip * ($degrees + ($minutes / 60.0) + ($seconds / 3600.0));
    }

    private function fractionToFloat(string $part): float
    {
        $parts = explode(
            '/',
            $part
        );
        if (count($parts) <= 1) {
            return (float) $part;
        }

        if ((float) $parts[1] === 0.0) {
            return (float) $parts[0];
        }

        return (float) $parts[0] / (float) $parts[1];
    }

    private function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
