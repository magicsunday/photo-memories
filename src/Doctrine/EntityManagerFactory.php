<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Memories\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use MagicSunday\Memories\Entity\Cluster;
use MagicSunday\Memories\Entity\Location;
use MagicSunday\Memories\Entity\Media;
use MagicSunday\Memories\Entity\MediaDuplicate;
use MagicSunday\Memories\Entity\Memory;
use PDO;
use Throwable;

use function class_exists;

/**
 * Class EntityManagerFactory.
 */
final class EntityManagerFactory
{
    /**
     * Create and return a configured EntityManagerInterface.
     *
     * @throws DbalException
     */
    public function create(): EntityManagerInterface
    {
        // Connection params (MariaDB/MySQL)
        $host     = getenv('MARIADB_HOST');
        $port     = getenv('MARIADB_PORT');
        $user     = getenv('MARIADB_USER');
        $password = getenv('MARIADB_PASSWORD');
        $dbname   = getenv('MARIADB_DATABASE');

        $dbParams = [
            'driver'        => 'pdo_mysql',
            'host'          => $host === false ? 'database' : $host,
            'port'          => $port === false ? 3306 : (int) $port,
            'user'          => $user === false ? 'memories' : $user,
            'password'      => $password === false ? 'memories' : $password,
            'dbname'        => $dbname === false ? 'memories' : $dbname,
            'charset'       => 'utf8mb4',
            'driverOptions' => [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
            ],
        ];

        // Metadata config (attributes)
        $isDevMode = true; // CLI tool; no HTTP request cycle, safe to enable metadata cache later
        $paths     = [__DIR__ . '/../Entity'];
        $config    = ORMSetup::createAttributeMetadataConfiguration($paths, $isDevMode);

        // Build DBAL connection and ORM EntityManager (ORM 3.x style)
        $connection = DriverManager::getConnection($dbParams);
        $em         = new EntityManager($connection, $config);

        // Keep schema in sync during development; prefer migrations in prod
        $this->ensureSchema($em);

        return $em;
    }

    private function ensureSchema(EntityManagerInterface $em): void
    {
        $classes = [
            Media::class,
            MediaDuplicate::class,
            Cluster::class,
            Memory::class,
            Location::class,
        ];

        $metadata = [];

        // Build metadata explicitly for known entities to avoid path scanning issues
        foreach ($classes as $entityClass) {
            if (class_exists($entityClass)) {
                try {
                    $metadata[] = $em->getClassMetadata($entityClass);
                } catch (Throwable) {
                    // Skip entities that are not fully mapped yet
                }
            }
        }

        if ($metadata === []) {
            return; // nothing to do
        }

        $tool = new SchemaTool($em);
        $tool->updateSchema($metadata);
    }
}
