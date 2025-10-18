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
 * Adds S2 cell identifiers and heuristic place metadata to the media table.
 */
final class Version20250216100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add s2CellId and placeId columns to media for staypoint heuristics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media ADD s2CellId VARCHAR(32) DEFAULT NULL");
        $this->addSql("ALTER TABLE media ADD placeId JSON DEFAULT NULL");
        $this->addSql("CREATE INDEX idx_media_s2_cell ON media (s2CellId)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_media_s2_cell');
        $this->addSql('ALTER TABLE media DROP COLUMN placeId');
        $this->addSql('ALTER TABLE media DROP COLUMN s2CellId');
    }
}
