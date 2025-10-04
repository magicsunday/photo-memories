<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250321090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add aggregated quality metrics and low quality flag to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD qualityScore DOUBLE PRECISION DEFAULT NULL,
    ADD qualityExposure DOUBLE PRECISION DEFAULT NULL,
    ADD qualityNoise DOUBLE PRECISION DEFAULT NULL,
    ADD lowQuality TINYINT(1) NOT NULL DEFAULT 0
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP qualityScore, DROP qualityExposure, DROP qualityNoise, DROP lowQuality');
    }
}
