<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Geocoding;

use Doctrine\ORM\EntityManagerInterface;
use MagicSunday\Memories\Entity\Location;

/**
 * Lightweight cell -> Location index backed by the DB.
 * Speeds up reverse-geocoding by reusing known Locations for a cell.
 */
final class LocationCellIndex
{
    /** @var array<string,int> cell -> location id */
    private array $cellToId = [];

    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Load (cell,id) pairs from DB into memory. Returns number of distinct cells loaded.
     */
    public function warmUpFromDb(?int $max = null): int
    {
        $q = $this->em->createQuery('SELECT l.id, l.cell FROM '.Location::class.' l');
        if ($max !== null && $max > 0) {
            $q->setMaxResults($max);
        }

        /** @var iterable<array{id:int,cell:string}> $it */
        $it = $q->toIterable([], \Doctrine\ORM\Query::HYDRATE_ARRAY);

        $n = 0;
        foreach ($it as $row) {
            $cell = (string) $row['cell'];
            if ($cell !== '' && !isset($this->cellToId[$cell])) {
                $this->cellToId[$cell] = (int) $row['id'];
                $n++;
            }
        }
        return $n;
    }

    /**
     * Try to resolve a managed Location by cell. Returns null if unknown.
     */
    public function findByCell(string $cell): ?Location
    {
        $id = $this->cellToId[$cell] ?? null;
        if ($id === null || $id <= 0) {
            // fallback: first location for this cell (uses DB index on cell)
            /** @var Location|null $loc */
            $loc = $this->em->getRepository(Location::class)->findOneBy(['cell' => $cell]);
            if ($loc instanceof Location) {
                $this->cellToId[$cell] = $loc->getId();
                return $loc;
            }
            return null;
        }

        /** @var Location|null $loc */
        $loc = $this->em->find(Location::class, $id);
        if ($loc instanceof Location) {
            return $loc;
        }

        // stale id: remove and retry repository fallback next time
        unset($this->cellToId[$cell]);
        return null;
    }

    /**
     * Remember that this cell now maps to $loc (after flush the id will be stable).
     */
    public function remember(string $cell, Location $loc): void
    {
        $id = $loc->getId() ?? 0;
        if ($id > 0) {
            $this->cellToId[$cell] = $id;
        }
    }
}
