<?php

namespace webdna\imageshop\migrations;

use craft\db\Migration;

class m260303_000000_add_sync_log_table extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%imageshop-dam_sync_log}}', [
            'id' => $this->primaryKey(),
            'dateCreated' => $this->dateTime()->notNull(),
            'documentsChanged' => $this->integer()->notNull()->defaultValue(0),
            'jobsQueued' => $this->integer()->notNull()->defaultValue(0),
            'status' => $this->string(32)->notNull(),
        ]);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%imageshop-dam_sync_log}}');

        return true;
    }
}
