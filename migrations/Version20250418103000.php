<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250418103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add face detection flags to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD hasFaces TINYINT(1) NOT NULL DEFAULT 0,
    ADD facesCount INT NOT NULL DEFAULT 0
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP hasFaces, DROP facesCount');
    }
}
