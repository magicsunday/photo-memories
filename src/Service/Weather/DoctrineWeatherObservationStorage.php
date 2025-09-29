<?php
/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Service\Weather;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\WeatherObservation;
use MagicSunday\Memories\Repository\WeatherObservationRepository;

final readonly class DoctrineWeatherObservationStorage implements WeatherObservationStorageInterface
{
    public function __construct(
        private WeatherObservationRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findHint(float $lat, float $lon, int $timestamp): ?array
    {
        $hash   = WeatherObservation::lookupHashFromRaw($lat, $lon, $timestamp);
        $stored = $this->repository->findOneByLookupHash($hash);

        return $stored?->getHint();
    }

    public function hasObservation(float $lat, float $lon, int $timestamp): bool
    {
        $hash = WeatherObservation::lookupHashFromRaw($lat, $lon, $timestamp);

        return $this->repository->existsByLookupHash($hash);
    }

    public function storeHint(float $lat, float $lon, int $timestamp, array $hint, string $source): void
    {
        $hash    = WeatherObservation::lookupHashFromRaw($lat, $lon, $timestamp);
        $current = $this->repository->findOneByLookupHash($hash);
        $observedAt = WeatherObservation::observationTimeFromTimestamp($timestamp);

        if ($current instanceof WeatherObservation) {
            $current->updateHint($observedAt, $hint, $source);
        } else {
            $current = WeatherObservation::createFromHint($lat, $lon, $timestamp, $hint, $source);
            $this->entityManager->persist($current);
        }

        $this->entityManager->flush();
    }
}
