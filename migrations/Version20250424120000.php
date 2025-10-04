<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250424120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexing metadata columns to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD featureVersion INT NOT NULL DEFAULT 0,
    ADD indexedAt DATETIME DEFAULT NULL,
    ADD indexLog LONGTEXT DEFAULT NULL,
    ADD needsRotation TINYINT(1) NOT NULL DEFAULT 0
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP featureVersion, DROP indexedAt, DROP indexLog, DROP needsRotation');
    }
}
