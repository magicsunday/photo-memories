<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250305090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add capture time metadata columns (timeSource, tzId, capturedLocal) to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD timeSource ENUM('EXIF', 'VIDEO_QUICKTIME', 'FILE_MTIME') DEFAULT NULL,
    ADD tzId VARCHAR(128) DEFAULT NULL,
    ADD capturedLocal DATETIME DEFAULT NULL
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP timeSource, DROP tzId, DROP capturedLocal');
    }
}
