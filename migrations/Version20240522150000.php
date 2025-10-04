<?php

/**
 * This file is part of the package magicsunday/photo-memories.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Align the media table with the entity metadata.
 */
final class Version20240522150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing media indexes and enforce the phashPrefix length.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable('media')) {
            return;
        }

        $table = $schema->getTable('media');

        $indexes = [
            'idx_media_candidate' => ['noShow', 'lowQuality', 'takenAt'],
            'idx_media_needs_geocode' => ['needsGeocode'],
            'idx_media_live_pair_id' => ['livePairMediaId'],
            'idx_media_location' => ['location_id'],
            'idx_media_geocell8' => ['geoCell8'],
            'idx_media_phash_prefix' => ['phashPrefix'],
            'idx_media_burst_taken' => ['burstUuid', 'takenAt'],
            'idx_media_video_taken' => ['isVideo', 'takenAt'],
        ];

        foreach ($indexes as $name => $columns) {
            if ($table->hasIndex($name)) {
                continue;
            }

            $table->addIndex($columns, $name);
        }

        if (! $table->hasColumn('phashPrefix')) {
            return;
        }

        $column = $table->getColumn('phashPrefix');
        if ($column->getLength() === 16) {
            return;
        }

        $table->changeColumn('phashPrefix', [
            'length'  => 16,
            'notnull' => false,
        ]);
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable('media')) {
            return;
        }

        $table = $schema->getTable('media');

        $indexes = [
            'idx_media_candidate',
            'idx_media_needs_geocode',
            'idx_media_live_pair_id',
            'idx_media_location',
            'idx_media_geocell8',
            'idx_media_phash_prefix',
            'idx_media_burst_taken',
            'idx_media_video_taken',
        ];

        foreach ($indexes as $name) {
            if (! $table->hasIndex($name)) {
                continue;
            }

            $table->dropIndex($name);
        }

        if (! $table->hasColumn('phashPrefix')) {
            return;
        }

        $table->changeColumn('phashPrefix', [
            'length'  => 255,
            'notnull' => false,
        ]);
    }
}
