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
        $this->createTable('{{%imageshop-dam_sync}}', [
            'id' => $this->primaryKey(),
            'lastUpdated' => $this->dateTime(),
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

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%imageshop-dam_sync_log}}');
        $this->dropTableIfExists('{{%imageshop-dam_sync}}');

        return true;
    }
}
