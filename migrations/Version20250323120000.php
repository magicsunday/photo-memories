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

final class Version20250323120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GeoHash precision columns and indexes for media coordinates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media ADD geohash7 VARCHAR(12) DEFAULT NULL");
        $this->addSql("ALTER TABLE media ADD geohash5 VARCHAR(12) DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_media_geohash7 ON media (geohash7)');
        $this->addSql('CREATE INDEX idx_media_geohash5 ON media (geohash5)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_media_geohash7 ON media');
        $this->addSql('DROP INDEX idx_media_geohash5 ON media');
        $this->addSql('ALTER TABLE media DROP geohash7');
        $this->addSql('ALTER TABLE media DROP geohash5');
    }
}
