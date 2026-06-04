<?php

namespace yellowrobot\courier\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string|null $triggerUid
 * @property string $templateHandle
 * @property string|null $channel
 * @property string $recipient
 * @property string $subject
 * @property string $status
 * @property string|null $errorMessage
 * @property bool $isTest
 * @property int|null $elementId
 * @property string|null $elementType
 * @property string $dateSent
 */
class EmailLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%courier_logs}}';
    }
}
