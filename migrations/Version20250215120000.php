<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the memories cluster persistence tables including spatial indexes.
 */
final class Version20250215120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create memories cluster, cluster_member and significant_place tables with spatial indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE memories_cluster (
                id BIGSERIAL NOT NULL,
                type VARCHAR(64) NOT NULL,
                strategy VARCHAR(64) NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                members_count INT NOT NULL DEFAULT 0,
                photo_count INT DEFAULT NULL,
                video_count INT DEFAULT NULL,
                key_media_id BIGINT DEFAULT NULL,
                location_id BIGINT DEFAULT NULL,
                algorithm_version VARCHAR(32) DEFAULT NULL,
                config_hash VARCHAR(64) DEFAULT NULL,
                centroid_lat DOUBLE PRECISION DEFAULT NULL,
                centroid_lon DOUBLE PRECISION DEFAULT NULL,
                centroid_cell7 VARCHAR(32) DEFAULT NULL,
                bounding_box JSONB DEFAULT NULL,
                score DOUBLE PRECISION DEFAULT NULL,
                score_pre_norm DOUBLE PRECISION DEFAULT NULL,
                score_post_norm DOUBLE PRECISION DEFAULT NULL,
                score_boosted DOUBLE PRECISION DEFAULT NULL,
                quality_score DOUBLE PRECISION DEFAULT NULL,
                people_score DOUBLE PRECISION DEFAULT NULL,
                meta JSONB DEFAULT '{}'::jsonb,
                start_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                CONSTRAINT uniq_memories_cluster_strategy_fp UNIQUE (strategy, fingerprint),
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql("CREATE INDEX idx_memories_cluster_strategy_fingerprint ON memories_cluster (strategy, fingerprint)");
        $this->addSql("CREATE INDEX idx_memories_cluster_timerange ON memories_cluster (type, start_at, end_at)");
        $this->addSql("CREATE INDEX idx_memories_cluster_centroid ON memories_cluster (centroid_lat, centroid_lon)");
        $this->addSql("CREATE INDEX idx_memories_cluster_members_count ON memories_cluster (members_count)");
        $this->addSql("CREATE INDEX idx_memories_cluster_location_id ON memories_cluster (location_id)");
        $this->addSql("CREATE INDEX idx_memories_cluster_key_media_id ON memories_cluster (key_media_id)");
        $this->addSql("CREATE INDEX idx_memories_cluster_bbox ON memories_cluster USING GIN (bounding_box)");
        $this->addSql("CREATE INDEX idx_memories_cluster_meta ON memories_cluster USING GIN (meta)");
        $this->addSql("CREATE INDEX idx_memories_cluster_centroid_cell7 ON memories_cluster (centroid_cell7)");

        $this->addSql(<<<'SQL'
            ALTER TABLE memories_cluster
            ADD CONSTRAINT fk_memories_cluster_key_media
            FOREIGN KEY (key_media_id) REFERENCES media (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE memories_cluster
            ADD CONSTRAINT fk_memories_cluster_location
            FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE memories_cluster_member (
                id BIGSERIAL NOT NULL,
                cluster_id BIGINT NOT NULL,
                media_id BIGINT NOT NULL,
                role VARCHAR(32) NOT NULL,
                local_score DOUBLE PRECISION DEFAULT NULL,
                ordering INT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql("CREATE INDEX idx_memories_cluster_member_cluster_order ON memories_cluster_member (cluster_id, ordering)");
        $this->addSql("CREATE INDEX idx_memories_cluster_member_media ON memories_cluster_member (media_id)");
        $this->addSql("CREATE UNIQUE INDEX uniq_memories_cluster_member ON memories_cluster_member (cluster_id, media_id)");

        $this->addSql(<<<'SQL'
            ALTER TABLE memories_cluster_member
            ADD CONSTRAINT fk_memories_cluster_member_cluster
            FOREIGN KEY (cluster_id) REFERENCES memories_cluster (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE memories_cluster_member
            ADD CONSTRAINT fk_memories_cluster_member_media
            FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE memories_significant_place (
                id BIGSERIAL NOT NULL,
                kind VARCHAR(32) NOT NULL,
                label VARCHAR(191) DEFAULT NULL,
                location_id BIGINT DEFAULT NULL,
                geometry JSONB NOT NULL,
                centroid_lat DOUBLE PRECISION DEFAULT NULL,
                centroid_lon DOUBLE PRECISION DEFAULT NULL,
                radius_meters DOUBLE PRECISION DEFAULT NULL,
                confidence DOUBLE PRECISION DEFAULT NULL,
                meta JSONB DEFAULT '{}'::jsonb,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql("CREATE INDEX idx_memories_significant_place_kind ON memories_significant_place (kind)");
        $this->addSql("CREATE INDEX idx_memories_significant_place_geometry ON memories_significant_place USING GIN (geometry)");

        $this->addSql(<<<'SQL'
            ALTER TABLE memories_significant_place
            ADD CONSTRAINT fk_memories_significant_place_location
            FOREIGN KEY (location_id) REFERENCES location (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE memories_significant_place DROP CONSTRAINT IF EXISTS fk_memories_significant_place_location');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_significant_place_geometry');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_significant_place_kind');
        $this->addSql('DROP TABLE IF EXISTS memories_significant_place');

        $this->addSql('ALTER TABLE memories_cluster_member DROP CONSTRAINT fk_memories_cluster_member_media');
        $this->addSql('ALTER TABLE memories_cluster_member DROP CONSTRAINT fk_memories_cluster_member_cluster');
        $this->addSql('DROP INDEX IF EXISTS uniq_memories_cluster_member');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_member_media');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_member_cluster_order');
        $this->addSql('DROP TABLE IF EXISTS memories_cluster_member');

        $this->addSql('ALTER TABLE memories_cluster DROP CONSTRAINT IF EXISTS fk_memories_cluster_location');
        $this->addSql('ALTER TABLE memories_cluster DROP CONSTRAINT IF EXISTS fk_memories_cluster_key_media');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_centroid_cell7');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_meta');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_bbox');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_key_media_id');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_location_id');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_members_count');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_centroid');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_timerange');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_strategy_fingerprint');
        $this->addSql('ALTER TABLE memories_cluster DROP CONSTRAINT IF EXISTS uniq_memories_cluster_strategy_fp');
        $this->addSql('DROP TABLE IF EXISTS memories_cluster');
    }
}
