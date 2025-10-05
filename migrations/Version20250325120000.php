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

final class Version20250325120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add burstRepresentative flag to media and supporting index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD burstRepresentative TINYINT(1) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_media_burst_repr ON media (burstUuid, burstRepresentative)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_media_burst_repr ON media');
        $this->addSql('ALTER TABLE media DROP burstRepresentative');
    }
}
