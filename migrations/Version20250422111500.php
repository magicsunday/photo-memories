<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250422111500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sceneTags column to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD sceneTags JSON DEFAULT NULL
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP sceneTags');
    }
}

