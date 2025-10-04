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

final class Version20250204120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add home_config_hash column to media table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD home_config_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP home_config_hash');
    }
}
