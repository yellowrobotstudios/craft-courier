<?php

namespace yellowrobot\courier\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use yii\db\ActiveQueryInterface;

/**
 * Trigger record — the DB-canonical wiring for an element-backed Trigger.
 *
 * @property int $id
 * @property string $handle
 * @property string $triggerMode
 * @property string|null $eventTrigger
 * @property string|null $rawEventClass
 * @property string|null $rawEventName
 * @property string|null $dateElementType
 * @property string|null $dateField
 * @property int $dateOffsetDays
 * @property string|null $dateSendTime
 * @property string|null $fixedDate
 * @property string|null $condition
 * @property string|null $recipients
 * @property string|null $cc
 * @property string|null $bcc
 * @property string|null $variables
 * @property string $channelIds
 * @property string $sendMode
 * @property int|null $templateId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property Element $element
 */
class TriggerRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%courier_triggers}}';
    }

    /**
     * Returns the trigger's element.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }
}
