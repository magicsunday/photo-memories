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

final class Version20250321120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add video rotation, stabilisation flag, and stream metadata columns to the media table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE media ADD videoRotationDeg DOUBLE PRECISION DEFAULT NULL, ADD videoHasStabilization TINYINT(1) DEFAULT NULL, ADD videoStreams LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP videoRotationDeg, DROP videoHasStabilization, DROP videoStreams');
    }
}
