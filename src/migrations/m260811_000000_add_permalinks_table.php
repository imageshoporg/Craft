<?php

namespace Imageshop\Imageshop\migrations;

use craft\db\Migration;

/**
 * m260811_000000_add_permalinks_table migration.
 *
 * Adds durable storage for Imageshop permalinks.
 *
 * Permalinks previously lived only in Craft's data cache. Because
 * /Permalink/CreatePermaLinkFromDocumentId mints a *new* permalink on every
 * call, any cache flush — a deploy running clear-caches, a container restart,
 * or simply a second app node with its own file cache — caused a fresh
 * permalink to be created. That handed CloudFront a URL it had never seen and
 * turned a warm 0.2s image into a multi-second cold generate.
 *
 * Keying stored permalinks on documentId + width + height means the create
 * call happens once per derivative and survives everything above.
 */
class m260811_000000_add_permalinks_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $table = '{{%imageshop-dam_permalinks}}';

        if ($this->db->getTableSchema($table)) {
            return true;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'documentId' => $this->integer()->notNull(),
            'width' => $this->integer()->notNull()->defaultValue(0),
            'height' => $this->integer()->notNull()->defaultValue(0),
            'url' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        // Unique so concurrent nodes converge on one permalink per derivative.
        // documentId is leftmost, so this also serves lookups by document alone.
        $this->createIndex(null, $table, ['documentId', 'width', 'height'], true);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%imageshop-dam_permalinks}}');

        return true;
    }
}
