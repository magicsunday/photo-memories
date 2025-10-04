<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250217120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add camera ownership, lens metadata, and composite image columns to media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD cameraOwner VARCHAR(128) DEFAULT NULL, ADD cameraBodySerial VARCHAR(64) DEFAULT NULL, ADD lensMake VARCHAR(128) DEFAULT NULL, ADD lensSpecification VARCHAR(128) DEFAULT NULL, ADD lensSerialNumber VARCHAR(64) DEFAULT NULL, ADD compositeImage SMALLINT DEFAULT NULL, ADD compositeImageSourceCount INT DEFAULT NULL, ADD compositeImageExposureTimes VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP cameraOwner, DROP cameraBodySerial, DROP lensMake, DROP lensSpecification, DROP lensSerialNumber, DROP compositeImage, DROP compositeImageSourceCount, DROP compositeImageExposureTimes');
    }
}
