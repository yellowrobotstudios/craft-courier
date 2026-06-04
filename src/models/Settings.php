<?php

namespace yellowrobot\courier\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

class Settings extends Model
{
    /** Optional Twig layout that wraps every rendered email body. */
    public ?string $defaultLayout = null;

    /** Delete logs older than this many days during garbage collection (null/0 = keep forever). */
    public ?int $logRetentionDays = null;

    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['defaultLayout'],
            ],
        ];
    }

    public function defineRules(): array
    {
        return [
            [['defaultLayout'], 'string'],
            [['logRetentionDays'], 'integer', 'min' => 0],
        ];
    }
}
