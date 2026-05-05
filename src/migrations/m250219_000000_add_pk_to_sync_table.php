<?php

namespace webdna\imageshop\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;

/**
 * m250219_000000_add_pk_to_sync_table migration.
 *
 * Adds a primary key column to the sync table for existing installations
 * and cleans up duplicate rows.
 */
class m250219_000000_add_pk_to_sync_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $tableName = '{{%imageshop-dam_sync}}';

        if (!$this->db->getTableSchema($tableName)) {
            // Table doesn't exist yet; Install migration will create it correctly
            return true;
        }

        $schema = $this->db->getTableSchema($tableName);

        // Only proceed if id column doesn't exist yet
        if ($schema->getColumn('id') !== null) {
            return true;
        }

        // Keep only the newest row (by lastUpdated), delete the rest
        $newestRow = (new Query())
            ->select(['lastUpdated'])
            ->from($tableName)
            ->orderBy(['lastUpdated' => SORT_DESC])
            ->scalar();

        if ($newestRow) {
            // Delete all rows except the one with the newest lastUpdated
            $allRows = (new Query())
                ->select(['lastUpdated'])
                ->from($tableName)
                ->orderBy(['lastUpdated' => SORT_DESC])
                ->column();

            if (count($allRows) > 1) {
                // Keep the first (newest), delete the rest
                // We can't rely on IDs since there are none, so truncate and re-insert
                $keepRow = (new Query())
                    ->select(['lastUpdated', 'documentCache'])
                    ->from($tableName)
                    ->orderBy(['lastUpdated' => SORT_DESC])
                    ->one();

                $this->delete($tableName);

                if ($keepRow) {
                    $this->insert($tableName, $keepRow);
                }
            }
        }

        // Add the primary key column
        $this->addColumn($tableName, 'id', $this->primaryKey()->first());

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $tableName = '{{%imageshop-dam_sync}}';

        if (!$this->db->getTableSchema($tableName)) {
            return true;
        }

        $schema = $this->db->getTableSchema($tableName);
        if ($schema->getColumn('id') !== null) {
            $this->dropColumn($tableName, 'id');
        }

        return true;
    }
}
