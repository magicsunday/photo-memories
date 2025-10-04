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

final class Version20250315093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add geocoding metadata columns and indexes to the location table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE location ADD attribution VARCHAR(255) DEFAULT NULL, ADD licence VARCHAR(255) DEFAULT NULL, ADD refreshedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', ADD stale TINYINT(1) DEFAULT 0 NOT NULL, ADD confidence DOUBLE PRECISION DEFAULT NULL, ADD accuracyRadiusMeters DOUBLE PRECISION DEFAULT NULL, ADD timezone VARCHAR(64) DEFAULT NULL, ADD osmType VARCHAR(16) DEFAULT NULL, ADD osmId VARCHAR(32) DEFAULT NULL, ADD wikidataId VARCHAR(32) DEFAULT NULL, ADD wikipedia VARCHAR(128) DEFAULT NULL, ADD altNames JSON DEFAULT NULL, ADD extraTags JSON DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_loc_country_city ON location (countryCode, city)');
        $this->addSql('UPDATE location SET refreshedAt = NOW() WHERE refreshedAt IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_loc_country_city ON location');
        $this->addSql('ALTER TABLE location DROP attribution, DROP licence, DROP refreshedAt, DROP stale, DROP confidence, DROP accuracyRadiusMeters, DROP timezone, DROP osmType, DROP osmId, DROP wikidataId, DROP wikipedia, DROP altNames, DROP extraTags');
    }
}
