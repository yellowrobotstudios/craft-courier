<?php

namespace yellowrobot\courier\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use yii\db\ActiveQueryInterface;

/**
 * Email template record — the DB-canonical content for an element-backed EmailTemplate.
 *
 * @property int $id
 * @property string $handle
 * @property string $subject
 * @property string $htmlBody
 * @property string|null $textBody
 * @property string|null $bodyFile
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property Element $element
 */
class EmailTemplateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%courier_templates}}';
    }

    /**
     * Returns the template's element.
     *
     * @return ActiveQueryInterface The relational query object.
     */
    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }
}
