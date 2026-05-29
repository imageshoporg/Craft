<?php

namespace Imageshop\Imageshop\migrations;

use craft\db\Migration;

class m260303_100000_add_sync_log_details extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn('{{%imageshop-dam_sync_log}}', 'details', $this->text()->after('status'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%imageshop-dam_sync_log}}', 'details');

        return true;
    }
}
