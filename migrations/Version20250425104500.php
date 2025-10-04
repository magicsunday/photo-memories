<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250425104500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for geocoding, duplicate detection and video feeds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX idx_media_geocell8 ON media (geoCell8)");
        $this->addSql("CREATE INDEX idx_media_phash_prefix ON media (phashPrefix)");
        $this->addSql("CREATE INDEX idx_media_burst_taken ON media (burstUuid, takenAt)");
        $this->addSql("CREATE INDEX idx_media_video_taken ON media (isVideo, takenAt)");
        $this->addSql("CREATE INDEX idx_media_location ON media (location_id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_media_geocell8 ON media');
        $this->addSql('DROP INDEX idx_media_phash_prefix ON media');
        $this->addSql('DROP INDEX idx_media_burst_taken ON media');
        $this->addSql('DROP INDEX idx_media_video_taken ON media');
        $this->addSql('DROP INDEX idx_media_location ON media');
    }
}
