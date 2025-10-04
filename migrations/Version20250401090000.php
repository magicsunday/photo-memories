<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250401090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pHash64 storage, burst index and live pair relation to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD phash64 BIGINT UNSIGNED DEFAULT NULL,
    ADD burstIndex INT DEFAULT NULL,
    ADD livePairMediaId BIGINT DEFAULT NULL
SQL
        );

        $this->addSql("CREATE INDEX idx_phash64 ON media (phash64)");
        $this->addSql("CREATE INDEX idx_live_pair_checksum ON media (livePairChecksum)");
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD CONSTRAINT FK_media_live_pair
        FOREIGN KEY (livePairMediaId)
        REFERENCES media (id)
        ON DELETE SET NULL
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_media_live_pair');
        $this->addSql('DROP INDEX idx_phash64 ON media');
        $this->addSql('DROP INDEX idx_live_pair_checksum ON media');
        $this->addSql('ALTER TABLE media DROP phash64, DROP burstIndex, DROP livePairMediaId');
    }
}
