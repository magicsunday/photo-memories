<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250327093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add content kind classification and no-show flag to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD contentKind VARCHAR(32) DEFAULT NULL,
    ADD noShow TINYINT(1) NOT NULL DEFAULT 0
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP contentKind, DROP noShow');
    }
}
