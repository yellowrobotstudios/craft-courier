<?php

namespace yellowrobot\courier\events;

use yii\base\Event;

/**
 * Lets other plugins/modules register custom event triggers, which appear
 * under a "Custom" category in the trigger event picker.
 *
 * Each entry is keyed by a unique trigger key and has the same shape as the
 * built-in registry entries (label, category, class, event, and optional
 * condition / objectExtractor / elementType).
 */
class RegisterEventTriggersEvent extends Event
{
    /** @var array<string,array<string,mixed>> */
    public array $triggers = [];
}
