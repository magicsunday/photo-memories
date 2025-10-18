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
        $this->addSql("CREATE TYPE memories_cluster_type AS ENUM ('significant_place', 'story', 'highlight', 'custom')");
        $this->addSql("CREATE TYPE memories_cluster_status AS ENUM ('draft', 'published', 'archived')");
        $this->addSql("CREATE TYPE memories_cluster_member_role AS ENUM ('primary', 'secondary', 'alternate')");
        $this->addSql("CREATE TYPE memories_significant_place_kind AS ENUM ('landmark', 'city', 'region', 'other')");

        $this->addSql(<<<'SQL'
            CREATE TABLE memories_cluster (
                id BIGSERIAL NOT NULL,
                cover_media_id BIGINT DEFAULT NULL,
                type memories_cluster_type NOT NULL,
                status memories_cluster_status NOT NULL DEFAULT 'draft',
                title VARCHAR(255) NOT NULL,
                subtitle VARCHAR(255) DEFAULT NULL,
                start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                end_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                score DOUBLE PRECISION DEFAULT NULL,
                centroid geometry(Point, 4326) NOT NULL,
                bounding_box geometry(Polygon, 4326) DEFAULT NULL,
                meta JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql("CREATE INDEX idx_memories_cluster_type_dates ON memories_cluster (type, start_at, end_at)");
        $this->addSql("CREATE INDEX idx_memories_cluster_centroid_gist ON memories_cluster USING GIST (centroid)");
        $this->addSql("CREATE INDEX idx_memories_cluster_bbox_gist ON memories_cluster USING GIST (bounding_box)");
        $this->addSql("CREATE INDEX idx_memories_cluster_meta_gin ON memories_cluster USING GIN (meta)");

        $this->addSql(<<<'SQL'
            ALTER TABLE memories_cluster
            ADD CONSTRAINT fk_memories_cluster_cover_media
            FOREIGN KEY (cover_media_id) REFERENCES media (id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE memories_cluster_member (
                id BIGSERIAL NOT NULL,
                cluster_id BIGINT NOT NULL,
                media_id BIGINT NOT NULL,
                role memories_cluster_member_role NOT NULL DEFAULT 'primary',
                position INT NOT NULL,
                weight DOUBLE PRECISION DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql("CREATE UNIQUE INDEX uniq_memories_cluster_member_pair ON memories_cluster_member (cluster_id, media_id)");

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
                cluster_id BIGINT NOT NULL,
                kind memories_significant_place_kind NOT NULL,
                confidence DOUBLE PRECISION DEFAULT NULL,
                visit_count INT DEFAULT 0,
                centroid geometry(Point, 4326) NOT NULL,
                bounding_box geometry(Polygon, 4326) DEFAULT NULL,
                radius_meters DOUBLE PRECISION DEFAULT NULL,
                highlights JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql("CREATE UNIQUE INDEX uniq_memories_significant_place_cluster ON memories_significant_place (cluster_id)");
        $this->addSql("CREATE INDEX idx_memories_significant_place_centroid_gist ON memories_significant_place USING GIST (centroid)");
        $this->addSql("CREATE INDEX idx_memories_significant_place_bbox_gist ON memories_significant_place USING GIST (bounding_box)");

        $this->addSql(<<<'SQL'
            ALTER TABLE memories_significant_place
            ADD CONSTRAINT fk_memories_significant_place_cluster
            FOREIGN KEY (cluster_id) REFERENCES memories_cluster (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE memories_significant_place DROP CONSTRAINT fk_memories_significant_place_cluster');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_significant_place_bbox_gist');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_significant_place_centroid_gist');
        $this->addSql('DROP INDEX IF EXISTS uniq_memories_significant_place_cluster');
        $this->addSql('DROP TABLE IF EXISTS memories_significant_place');

        $this->addSql('ALTER TABLE memories_cluster_member DROP CONSTRAINT fk_memories_cluster_member_media');
        $this->addSql('ALTER TABLE memories_cluster_member DROP CONSTRAINT fk_memories_cluster_member_cluster');
        $this->addSql('DROP INDEX IF EXISTS uniq_memories_cluster_member_pair');
        $this->addSql('DROP TABLE IF EXISTS memories_cluster_member');

        $this->addSql('ALTER TABLE memories_cluster DROP CONSTRAINT fk_memories_cluster_cover_media');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_meta_gin');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_bbox_gist');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_centroid_gist');
        $this->addSql('DROP INDEX IF EXISTS idx_memories_cluster_type_dates');
        $this->addSql('DROP TABLE IF EXISTS memories_cluster');

        $this->addSql('DROP TYPE IF EXISTS memories_significant_place_kind');
        $this->addSql('DROP TYPE IF EXISTS memories_cluster_member_role');
        $this->addSql('DROP TYPE IF EXISTS memories_cluster_status');
        $this->addSql('DROP TYPE IF EXISTS memories_cluster_type');
    }
}
