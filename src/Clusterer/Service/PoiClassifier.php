<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Clusterer\Service;

use MagicSunday\Memories\Clusterer\Contract\PoiClassifierInterface;
use MagicSunday\Memories\Entity\Location;

use function array_any;
use function array_keys;
use function is_array;
use function is_string;
use function str_contains;
use function strtolower;

/**
 * Default point-of-interest classifier used within clustering heuristics.
 */
final class PoiClassifier implements PoiClassifierInterface
{
    private const array TOURISM_KEYWORDS = [
        'tourism',
        'attraction',
        'beach',
        'museum',
        'national_park',
        'viewpoint',
        'hotel',
        'camp_site',
        'ski',
        'marina',
    ];

    private const array TRANSPORT_KEYWORDS = [
        'airport',
        'aerodrome',
        'railway_station',
        'train_station',
        'bus_station',
    ];

    public function isPoiSample(Location $location): bool
    {
        $pois = $location->getPois();
        if (!is_array($pois)) {
            return false;
        }

        if (array_any(
            $pois,
            static function (mixed $poi): bool {
                if (!is_array($poi)) {
                    return false;
                }

                if (isset($poi['categoryKey']) && is_string($poi['categoryKey']) && $poi['categoryKey'] !== '') {
                    return true;
                }

                if (isset($poi['categoryValue']) && is_string($poi['categoryValue']) && $poi['categoryValue'] !== '') {
                    return true;
                }

                $tags = $poi['tags'] ?? null;
                if (!is_array($tags)) {
                    return false;
                }

                return $tags !== [];
            }
        )) {
            return true;
        }

        if ($this->matchesKeyword($location->getCategory(), self::TOURISM_KEYWORDS)) {
            return true;
        }

        if ($this->matchesKeyword($location->getType(), self::TOURISM_KEYWORDS)) {
            return true;
        }

        return $location->getType() !== null;
    }

    public function isTourismPoi(Location $location): bool
    {
        if ($this->matchesKeyword($location->getCategory(), self::TOURISM_KEYWORDS)) {
            return true;
        }

        if ($this->matchesKeyword($location->getType(), self::TOURISM_KEYWORDS)) {
            return true;
        }

        $pois = $location->getPois();
        if (!is_array($pois)) {
            return false;
        }

        return array_any(
            $pois,
            function (mixed $poi): bool {
                if (!is_array($poi)) {
                    return false;
                }

                $categoryKey   = $poi['categoryKey'] ?? null;
                $categoryValue = $poi['categoryValue'] ?? null;
                if ($this->matchesKeyword($categoryKey, self::TOURISM_KEYWORDS)) {
                    return true;
                }

                if ($this->matchesKeyword($categoryValue, self::TOURISM_KEYWORDS)) {
                    return true;
                }

                $tags = $poi['tags'] ?? null;
                if (!is_array($tags)) {
                    return false;
                }

                return array_any(
                    array_keys($tags),
                    fn (string|int $tagKey): bool => $this->matchesKeyword(is_string($tagKey) ? $tagKey : null, self::TOURISM_KEYWORDS)
                ) || array_any(
                    $tags,
                    fn (mixed $tagValue): bool => $this->matchesKeyword(is_string($tagValue) ? $tagValue : null, self::TOURISM_KEYWORDS)
                );
            }
        );
    }

    public function isTransportPoi(Location $location): bool
    {
        if ($this->matchesKeyword($location->getCategory(), self::TRANSPORT_KEYWORDS)) {
            return true;
        }

        if ($this->matchesKeyword($location->getType(), self::TRANSPORT_KEYWORDS)) {
            return true;
        }

        $pois = $location->getPois();
        if (!is_array($pois)) {
            return false;
        }

        return array_any(
            $pois,
            function (mixed $poi): bool {
                if (!is_array($poi)) {
                    return false;
                }

                $categoryKey   = $poi['categoryKey'] ?? null;
                $categoryValue = $poi['categoryValue'] ?? null;
                if ($this->matchesKeyword($categoryKey, self::TRANSPORT_KEYWORDS)) {
                    return true;
                }

                if ($this->matchesKeyword($categoryValue, self::TRANSPORT_KEYWORDS)) {
                    return true;
                }

                $tags = $poi['tags'] ?? null;
                if (!is_array($tags)) {
                    return false;
                }

                return array_any(
                    array_keys($tags),
                    fn (string|int $tagKey): bool => $this->matchesKeyword(is_string($tagKey) ? $tagKey : null, self::TRANSPORT_KEYWORDS)
                ) || array_any(
                    $tags,
                    fn (mixed $tagValue): bool => $this->matchesKeyword(is_string($tagValue) ? $tagValue : null, self::TRANSPORT_KEYWORDS)
                );
            }
        );
    }

    /**
     * @param list<string> $keywords
     */
    private function matchesKeyword(?string $value, array $keywords): bool
    {
        if ($value === null) {
            return false;
        }

        $needle = strtolower($value);

        return array_any(
            $keywords,
            static fn (string $keyword): bool => $needle === strtolower($keyword) || str_contains($needle, strtolower($keyword))
        );
    }
}
