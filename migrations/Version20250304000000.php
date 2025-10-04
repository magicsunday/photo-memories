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

final class Version20250304000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand phashPrefix column to 32 characters for 128-bit hashes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media CHANGE phashPrefix phashPrefix VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media CHANGE phashPrefix phashPrefix VARCHAR(16) DEFAULT NULL');
    }
}
