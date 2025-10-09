<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Support;

/**
 * Helper trait that assigns Doctrine identifiers in unit tests.
 */
trait EntityIdAssignmentTrait
{
    /**
     * @param int|string $id accepts either integer or string identifiers to support UUID entities
     */
    protected function assignEntityId(object $entity, int|string $id, string $property = 'id'): void
    {
        $class      = new \ReflectionClass($entity);
        $reflection = null;

        while ($class !== false) {
            if ($class->hasProperty($property)) {
                $reflection = $class->getProperty($property);

                break;
            }

            $class = $class->getParentClass();
        }

        if ($reflection === null) {
            throw new \RuntimeException(sprintf('Property "%s" could not be located on %s or its parents.', $property, $entity::class));
        }

        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}

