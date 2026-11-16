<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Test\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use MagicSunday\Memories\Repository\ClusterRepository;
use MagicSunday\Memories\Test\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ClusterRepository::class)]
final class ClusterRepositoryTest extends TestCase
{
    #[Test]
    public function findLatestOrdersByStartAt(): void
    {
        $em = self::createStub(EntityManagerInterface::class);

        $query = self::getStubBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getStub();
        $query->method('getResult')->willReturn([]);

        $qb = self::getStubBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'from', 'orderBy', 'addOrderBy', 'setMaxResults', 'getQuery'])
            ->getStub();

        /** @var list<array{string,string}> $orderBys */
        $orderBys = [];
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('orderBy')->willReturnCallback(static function (string $field, string $direction) use (&$orderBys, &$qb): QueryBuilder {
            $orderBys[] = [$field, $direction];

            return $qb;
        });
        $qb->method('addOrderBy')->willReturnCallback(static function (string $field, string $direction) use (&$orderBys, &$qb): QueryBuilder {
            $orderBys[] = [$field, $direction];

            return $qb;
        });
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $em->method('createQueryBuilder')->willReturn($qb);

        $repository = new ClusterRepository($em);
        $repository->findLatest(5);

        self::assertSame([
            ['c.startAt', 'DESC'],
            ['c.createdAt', 'DESC'],
        ], $orderBys);
    }
}
