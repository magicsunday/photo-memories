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

final class Version20250316090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add xxHash64 fast checksum column and index to the media table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media ADD fastChecksumXxhash64 VARCHAR(16) DEFAULT NULL");
        $this->addSql('CREATE INDEX idx_fast_checksum_xxhash64 ON media (fastChecksumXxhash64)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_fast_checksum_xxhash64 ON media');
        $this->addSql('ALTER TABLE media DROP fastChecksumXxhash64');
    }
}
