<?php

namespace Imageshop\Imageshop\migrations;

use Craft;
use craft\db\Migration;

/**
 * Install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // One row per sync run. `lastUpdated` is the watermark the run
        // currently vouches for; `watermark` is the one it will vouch for once
        // every job it queued has completed. See services\Sync.
        $this->createTable('{{%imageshop-dam_sync}}', [
            'id' => $this->primaryKey(),
            'lastUpdated' => $this->dateTime(),
            'watermark' => $this->dateTime()->null(),
            'jobsQueued' => $this->integer()->notNull()->defaultValue(0),
            'jobsCompleted' => $this->integer()->notNull()->defaultValue(0),
            'jobsFailed' => $this->integer()->notNull()->defaultValue(0),
            'documentCache' => $this->longText()
        ]);

        $this->createTable('{{%imageshop-dam_sync_log}}', [
            'id' => $this->primaryKey(),
            'dateCreated' => $this->dateTime()->notNull(),
            'documentsChanged' => $this->integer()->notNull()->defaultValue(0),
            'jobsQueued' => $this->integer()->notNull()->defaultValue(0),
            'status' => $this->string(32)->notNull(),
            'details' => $this->text(),
        ]);

        // Durable permalink store. See m260811_000000_add_permalinks_table for
        // why these must not live in a volatile cache.
        $this->createTable('{{%imageshop-dam_permalinks}}', [
            'id' => $this->primaryKey(),
            'documentId' => $this->integer()->notNull(),
            'width' => $this->integer()->notNull()->defaultValue(0),
            'height' => $this->integer()->notNull()->defaultValue(0),
            'url' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%imageshop-dam_permalinks}}', ['documentId', 'width', 'height'], true);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%imageshop-dam_permalinks}}');
        $this->dropTableIfExists('{{%imageshop-dam_sync_log}}');
        $this->dropTableIfExists('{{%imageshop-dam_sync}}');

        return true;
    }
}
