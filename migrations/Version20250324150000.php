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

final class Version20250324150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create media_duplicate table storing perceptual duplicate distances.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE media_duplicate (
                id BIGINT AUTO_INCREMENT NOT NULL,
                media_left_id BIGINT NOT NULL,
                media_right_id BIGINT NOT NULL,
                distance SMALLINT NOT NULL,
                createdAt DATETIME NOT NULL,
                updatedAt DATETIME DEFAULT NULL,
                INDEX idx_media_duplicate_left (media_left_id),
                INDEX idx_media_duplicate_right (media_right_id),
                UNIQUE INDEX uniq_media_duplicate_pair (media_left_id, media_right_id),
                CONSTRAINT FK_media_duplicate_left FOREIGN KEY (media_left_id) REFERENCES media (id) ON DELETE CASCADE,
                CONSTRAINT FK_media_duplicate_right FOREIGN KEY (media_right_id) REFERENCES media (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media_duplicate');
    }
}
