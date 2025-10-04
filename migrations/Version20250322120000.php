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

final class Version20250322120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RAW/HEIC/HEVC indicator columns to the media table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media ADD isRaw TINYINT(1) DEFAULT '0' NOT NULL, ADD isHeic TINYINT(1) DEFAULT '0' NOT NULL, ADD isHevc TINYINT(1) DEFAULT '0' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP isRaw, DROP isHeic, DROP isHevc');
    }
}
