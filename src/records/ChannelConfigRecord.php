<?php

namespace yellowrobot\courier\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $type
 * @property string|null $settings
 * @property bool $enabled
 * @property int|null $sortOrder
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ChannelConfigRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%courier_channels}}';
    }
}
