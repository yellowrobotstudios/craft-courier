<?php

namespace yellowrobot\courier\records;

use craft\db\ActiveRecord;

/**
 * A pending time-shifted send: trigger × element × resolved date. Inserted by
 * the scheduler scan (date triggers), promoted to an immediate send job once
 * sendAt is due — after a send-time re-check against current element state.
 *
 * @property int $id
 * @property int $triggerId
 * @property int $elementId
 * @property string $resolvedDate
 * @property string $sendAt
 * @property string|null $processedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class ScheduledSendRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%courier_scheduled}}';
    }
}
