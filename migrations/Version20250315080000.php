<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250315080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add needsGeocode flag to media and initialise existing entries based on GPS/location state';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
ALTER TABLE media
    ADD needsGeocode TINYINT(1) NOT NULL DEFAULT 0
SQL
        );

        $this->addSql(<<<'SQL'
UPDATE media
   SET needsGeocode = 1
 WHERE gpsLat IS NOT NULL
   AND gpsLon IS NOT NULL
   AND (location_id IS NULL OR location_id = 0)
SQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP needsGeocode');
    }
}
