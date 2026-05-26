<?php

namespace Imageshop\Imageshop\migrations;

use Craft;
use craft\db\Migration;
use Imageshop\Imageshop\fields\ImageShopField;

/**
 * m260324_000000_change_field_columns_to_mediumtext migration.
 *
 * Changes all ImageShop field content columns from TEXT to MEDIUMTEXT
 * to support larger JSON payloads (e.g. gallery fields with many images).
 */
class m260324_000000_change_field_columns_to_mediumtext extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $fields = Craft::$app->getFields()->getFieldsByType(ImageShopField::class);

        foreach ($fields as $field) {
            $column = 'field_' . $field->handle . '_' . $field->columnSuffix;

            $contentTable = '{{%content}}';
            $schema = $this->db->getTableSchema($contentTable);

            if ($schema && $schema->getColumn($column) !== null) {
                $this->alterColumn($contentTable, $column, 'mediumtext');
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $fields = Craft::$app->getFields()->getFieldsByType(ImageShopField::class);

        foreach ($fields as $field) {
            $column = 'field_' . $field->handle . '_' . $field->columnSuffix;

            $contentTable = '{{%content}}';
            $schema = $this->db->getTableSchema($contentTable);

            if ($schema && $schema->getColumn($column) !== null) {
                $this->alterColumn($contentTable, $column, $this->text());
            }
        }

        return true;
    }
}
