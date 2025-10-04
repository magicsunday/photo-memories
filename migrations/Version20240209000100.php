<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use MagicSunday\Memories\Utility\GeoCell;

use function array_is_list;
use function array_map;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function sha1;

final class Version20240209000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rich metadata columns to cluster table and backfill values';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $indexes  = $this->connection->createSchemaManager()->listTableIndexes('cluster');

        foreach ($indexes as $index) {
            if ($index->isUnique() && $index->getColumns() === ['fingerprint']) {
                $this->addSql($platform->getDropIndexSQL($index, 'cluster'));
            }
        }

        $this->addSql('ALTER TABLE cluster ADD startAt DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD endAt DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD membersCount INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE cluster ADD photoCount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD videoCount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD cover_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD location_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD algorithmVersion VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD configHash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD centroidLat DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD centroidLon DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE cluster ADD centroidCell7 VARCHAR(32) DEFAULT NULL');

        $this->addSql('CREATE INDEX idx_cluster_start_at ON cluster (startAt)');
        $this->addSql('CREATE INDEX idx_cluster_end_at ON cluster (endAt)');
        $this->addSql('CREATE INDEX idx_cluster_members_count ON cluster (membersCount)');
        $this->addSql('CREATE INDEX idx_cluster_cover_id ON cluster (cover_id)');
        $this->addSql('CREATE INDEX idx_cluster_location_id ON cluster (location_id)');
        $this->addSql('CREATE INDEX idx_cluster_centroid_cell7 ON cluster (centroidCell7)');
        $this->addSql('CREATE INDEX idx_cluster_config_hash ON cluster (configHash)');
        $this->addSql('ALTER TABLE cluster ADD CONSTRAINT FK_cluster_cover_media FOREIGN KEY (cover_id) REFERENCES media (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE cluster ADD CONSTRAINT FK_cluster_location_location FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL');

        $this->backfillClusterMetadata();
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cluster DROP FOREIGN KEY FK_cluster_cover_media');
        $this->addSql('ALTER TABLE cluster DROP FOREIGN KEY FK_cluster_location_location');
        $this->addSql('DROP INDEX idx_cluster_start_at ON cluster');
        $this->addSql('DROP INDEX idx_cluster_end_at ON cluster');
        $this->addSql('DROP INDEX idx_cluster_members_count ON cluster');
        $this->addSql('DROP INDEX idx_cluster_cover_id ON cluster');
        $this->addSql('DROP INDEX idx_cluster_location_id ON cluster');
        $this->addSql('DROP INDEX idx_cluster_centroid_cell7 ON cluster');
        $this->addSql('DROP INDEX idx_cluster_config_hash ON cluster');
        $this->addSql('ALTER TABLE cluster DROP startAt, DROP endAt, DROP membersCount, DROP photoCount, DROP videoCount, DROP cover_id, DROP location_id, DROP algorithmVersion, DROP configHash, DROP centroidLat, DROP centroidLon, DROP centroidCell7');
        $this->addSql('CREATE UNIQUE INDEX uniq_cluster_fingerprint ON cluster (fingerprint)');
    }

    private function backfillClusterMetadata(): void
    {
        $clusters = $this->connection->fetchAllAssociative('SELECT id, members, centroid, params FROM cluster');

        foreach ($clusters as $cluster) {
            $id = (int) $cluster['id'];
            $memberIds = $this->decodeMembers($cluster['members'] ?? '[]');
            $membersCount = count($memberIds);

            $photoCount = null;
            $videoCount = null;
            $startAt    = null;
            $endAt      = null;

            if ($memberIds !== []) {
                $rows = $this->connection->executeQuery(
                    'SELECT id, takenAt, isVideo FROM media WHERE id IN (?)',
                    [$memberIds],
                    [ArrayParameterType::INTEGER]
                )->fetchAllAssociative();

                if ($rows !== []) {
                    $photoCount = 0;
                    $videoCount = 0;

                    foreach ($rows as $row) {
                        $isVideo = (int) ($row['isVideo'] ?? 0) === 1;
                        if ($isVideo) {
                            ++$videoCount;
                        } else {
                            ++$photoCount;
                        }

                        $takenAtRaw = $row['takenAt'] ?? null;
                        if ($takenAtRaw === null) {
                            continue;
                        }

                        $dt = new DateTimeImmutable((string) $takenAtRaw);
                        if ($startAt === null || $dt < $startAt) {
                            $startAt = $dt;
                        }

                        if ($endAt === null || $dt > $endAt) {
                            $endAt = $dt;
                        }
                    }
                }
            }

            $centroidLat = null;
            $centroidLon = null;
            $centroidCell = null;
            $centroidData = json_decode((string) ($cluster['centroid'] ?? 'null'), true);
            if (is_array($centroidData)) {
                $centroidLat = $this->numericOrNull($centroidData['lat'] ?? null);
                $centroidLon = $this->numericOrNull($centroidData['lon'] ?? null);
                if ($centroidLat !== null && $centroidLon !== null) {
                    $centroidCell = GeoCell::fromPoint($centroidLat, $centroidLon, 7);
                }
            }

            $params = json_decode((string) ($cluster['params'] ?? 'null'), true);
            $params = is_array($params) ? $params : [];
            $algorithmVersion = $this->resolveAlgorithmVersion($params);
            $configHash       = $this->computeConfigHash($params);

            $this->connection->update(
                'cluster',
                [
                    'membersCount'      => $membersCount,
                    'photoCount'        => $photoCount,
                    'videoCount'        => $videoCount,
                    'startAt'           => $startAt?->format('Y-m-d H:i:s'),
                    'endAt'             => $endAt?->format('Y-m-d H:i:s'),
                    'centroidLat'       => $centroidLat,
                    'centroidLon'       => $centroidLon,
                    'centroidCell7'     => $centroidCell,
                    'algorithmVersion'  => $algorithmVersion,
                    'configHash'        => $configHash,
                ],
                ['id' => $id],
                [
                    'membersCount'     => ParameterType::INTEGER,
                    'photoCount'       => ParameterType::INTEGER,
                    'videoCount'       => ParameterType::INTEGER,
                    'startAt'          => Types::DATETIME_MUTABLE,
                    'endAt'            => Types::DATETIME_MUTABLE,
                    'centroidLat'      => ParameterType::FLOAT,
                    'centroidLon'      => ParameterType::FLOAT,
                    'centroidCell7'    => ParameterType::STRING,
                    'algorithmVersion' => ParameterType::STRING,
                    'configHash'       => ParameterType::STRING,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveAlgorithmVersion(array $params): ?string
    {
        $candidates = [
            $params['algorithm_version'] ?? null,
            $params['algorithmVersion'] ?? null,
            $params['version'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }

            if (is_int($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function computeConfigHash(array $params): ?string
    {
        if ($params === []) {
            return null;
        }

        $normalized = $this->normaliseParams($params);
        $encoded    = json_encode($normalized);

        if ($encoded === false) {
            return null;
        }

        return sha1($encoded);
    }

    /**
     * @param array<string|int, mixed> $value
     *
     * @return array<string|int, mixed>
     */
    private function normaliseParams(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(function ($entry): mixed {
                if (is_array($entry)) {
                    return $this->normaliseParams($entry);
                }

                return $entry;
            }, $value);
        }

        ksort($value);

        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->normaliseParams($entry);
            }
        }

        return $value;
    }

    private function decodeMembers(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $value) {
            if (is_int($value)) {
                $ids[] = $value;
                continue;
            }

            if (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $ids;
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value) || is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
