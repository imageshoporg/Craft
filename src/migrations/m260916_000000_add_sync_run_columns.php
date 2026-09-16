<?php

namespace Imageshop\Imageshop\migrations;

use craft\db\Migration;

/**
 * Adds per-run job tracking to the sync run table.
 *
 * A queue-mode sync run may only advance the sync watermark once every job it
 * queued has succeeded. The run row therefore records the watermark it will
 * vouch for (`watermark`) separately from the one it currently vouches for
 * (`lastUpdated`), plus how many jobs it queued and how many have completed
 * or failed.
 */
class m260916_000000_add_sync_run_columns extends Migration
{
    private const TABLE = '{{%imageshop-dam_sync}}';

    public function safeUp(): bool
    {
        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            // Install migration creates the table with these columns.
            return true;
        }

        if ($schema->getColumn('watermark') === null) {
            $this->addColumn(self::TABLE, 'watermark', $this->dateTime()->null()->after('lastUpdated'));
        }
        if ($schema->getColumn('jobsQueued') === null) {
            $this->addColumn(self::TABLE, 'jobsQueued', $this->integer()->notNull()->defaultValue(0)->after('watermark'));
        }
        if ($schema->getColumn('jobsCompleted') === null) {
            $this->addColumn(self::TABLE, 'jobsCompleted', $this->integer()->notNull()->defaultValue(0)->after('jobsQueued'));
        }
        if ($schema->getColumn('jobsFailed') === null) {
            $this->addColumn(self::TABLE, 'jobsFailed', $this->integer()->notNull()->defaultValue(0)->after('jobsCompleted'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $schema = $this->db->getTableSchema(self::TABLE, true);
        if ($schema === null) {
            return true;
        }

        foreach (['jobsFailed', 'jobsCompleted', 'jobsQueued', 'watermark'] as $column) {
            if ($schema->getColumn($column) !== null) {
                $this->dropColumn(self::TABLE, $column);
            }
        }

        return true;
    }
}
