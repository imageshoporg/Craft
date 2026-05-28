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
        $this->createTable('{{%imageshop-plugin_sync}}', [
            'id' => $this->primaryKey(),
            'lastUpdated' => $this->dateTime(),
            'documentCache' => $this->longText()
        ]);

        $this->createTable('{{%imageshop-plugin_sync_log}}', [
            'id' => $this->primaryKey(),
            'dateCreated' => $this->dateTime()->notNull(),
            'documentsChanged' => $this->integer()->notNull()->defaultValue(0),
            'jobsQueued' => $this->integer()->notNull()->defaultValue(0),
            'status' => $this->string(32)->notNull(),
            'details' => $this->text(),
        ]);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%imageshop-plugin_sync_log}}');
        $this->dropTableIfExists('{{%imageshop-plugin_sync}}');

        return true;
    }
}
