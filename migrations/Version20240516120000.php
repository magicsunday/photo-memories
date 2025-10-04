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
 * Add an index for the live photo pair relation.
 */
final class Version20240516120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the idx_media_live_pair_id index for media.livePairMediaId.';
    }

    public function up(Schema $schema): void
    {
        if (! $schema->hasTable('media')) {
            return;
        }

        $table = $schema->getTable('media');

        if ($table->hasIndex('idx_media_live_pair_id')) {
            return;
        }

        $table->addIndex(['livePairMediaId'], 'idx_media_live_pair_id');
    }

    public function down(Schema $schema): void
    {
        if (! $schema->hasTable('media')) {
            return;
        }

        $table = $schema->getTable('media');

        if (! $table->hasIndex('idx_media_live_pair_id')) {
            return;
        }

        $table->dropIndex('idx_media_live_pair_id');
    }
}
