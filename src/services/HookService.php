<?php

namespace yellowrobot\courier\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\Queue;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\Trigger;
use yellowrobot\courier\jobs\SendCourierJob;
use yii\base\Event;

/**
 * Registers Yii event listeners for the CP-managed DB triggers and routes
 * matching events to per-channel send jobs.
 */
class HookService extends Component
{
    /** Cache key for the registered DB-trigger set (busted on trigger save/delete). */
    public const TRIGGER_CACHE_KEY = 'courier:triggers';

    /**
     * Invalidate the cached set of DB triggers. Called when a Trigger element is
     * saved or deleted so listener registration picks up the change next request.
     */
    public function invalidateTriggerCache(): void
    {
        Craft::$app->getCache()->delete(self::TRIGGER_CACHE_KEY);
    }

    /**
     * Register Yii event listeners for every enabled DB trigger.
     * Descriptors are cached and busted on trigger save/delete.
     */
    public function registerTriggerListeners(): void
    {
        // The plugin can boot once before install/migrations have created its
        // tables (e.g. mid-install, or with a migration still pending). Bail
        // quietly until the schema is in place so boot doesn't fatal querying a
        // missing table.
        if (!Craft::$app->getDb()->tableExists('{{%courier_triggers}}')) {
            return;
        }

        $registry = Courier::$plugin->events;

        foreach ($this->getTriggerDescriptors() as $descriptor) {
            // Registry event (the common path) resolves class/event/objectExtractor/
            // condition from the curated map. A raw "Other…" event carries its own
            // class + event name and binds the object to $event->sender by default.
            if (!empty($descriptor['eventTrigger'])) {
                $entry = $registry->get($descriptor['eventTrigger']);
            } elseif (!empty($descriptor['rawEventClass']) && !empty($descriptor['rawEventName'])) {
                $entry = ['class' => $descriptor['rawEventClass'], 'event' => $descriptor['rawEventName']];
            } else {
                continue;
            }

            if (empty($entry['class']) || empty($entry['event'])) {
                continue;
            }

            Event::on(
                $entry['class'],
                $entry['event'],
                function (Event $event) use ($descriptor, $entry) {
                    $this->_handleTriggerEvent((int) $descriptor['id'], $entry, $event);
                }
            );
        }
    }

    /**
     * Lightweight descriptors for enabled triggers (cached). Each is either a
     * registry trigger (eventTrigger set) or a raw event (rawEventClass + rawEventName).
     *
     * @return array<int,array{id:int,eventTrigger:?string,rawEventClass:?string,rawEventName:?string}>
     */
    private function getTriggerDescriptors(): array
    {
        return Craft::$app->getCache()->getOrSet(self::TRIGGER_CACHE_KEY, function () {
            $out = [];
            foreach (Trigger::find()->status('enabled')->all() as $trigger) {
                /** @var Trigger $trigger */
                $hasRaw = $trigger->rawEventClass && $trigger->rawEventName;
                if (!$trigger->eventTrigger && !$hasRaw) {
                    continue;
                }
                $out[] = [
                    'id' => (int) $trigger->id,
                    'eventTrigger' => $trigger->eventTrigger,
                    'rawEventClass' => $trigger->rawEventClass,
                    'rawEventName' => $trigger->rawEventName,
                ];
            }
            return $out;
        });
    }

    private function _handleTriggerEvent(int $triggerId, array $entry, Event $event): void
    {
        if ($this->_shouldSkipEvent($event)) {
            return;
        }

        // Built-in registry gate (e.g. isNew for "created")
        if (isset($entry['condition'])) {
            try {
                if (!($entry['condition'])($event)) {
                    return;
                }
            } catch (\Throwable $e) {
                Craft::error("Trigger registry condition threw: {$e->getMessage()}", __METHOD__);
                return;
            }
        }

        // Resolve the bound object (not always $event->sender)
        try {
            $object = isset($entry['objectExtractor'])
                ? ($entry['objectExtractor'])($event)
                : ($event->sender ?? null);
        } catch (\Throwable $e) {
            Craft::error("Trigger objectExtractor threw: {$e->getMessage()}", __METHOD__);
            return;
        }

        $trigger = Trigger::find()->id($triggerId)->status(null)->one();
        if (!$trigger || !$trigger->enabled) {
            return;
        }

        // Visual condition (element conditions only apply to elements). Groups are
        // OR-combined; matchesConditions() handles the empty/no-gate case.
        if ($trigger->condition && $object instanceof ElementInterface) {
            try {
                if (!$trigger->matchesConditions($object)) {
                    return;
                }
            } catch (\Throwable $e) {
                Craft::error("Trigger '{$trigger->handle}' condition eval failed: {$e->getMessage()}", __METHOD__);
                return;
            }
        }

        // Event-derived extras (e.g. which groups were just added) so conditions and
        // templates can reach data that isn't on the bound element itself.
        $extraVariables = [];
        if (isset($entry['variables'])) {
            try {
                $extraVariables = ($entry['variables'])($event);
            } catch (\Throwable $e) {
                Craft::error("Trigger variables extractor threw: {$e->getMessage()}", __METHOD__);
            }
        }

        $this->queueTriggerSends($trigger, $object, $extraVariables);
    }

    /**
     * Build the render variables for a trigger's bound object and queue one send
     * job per channel. The shared tail of both fire paths — event listeners and
     * the scheduler's promoted (time-shifted) sends.
     */
    public function queueTriggerSends(Trigger $trigger, mixed $object, array $extraVariables = []): bool
    {
        $variables = array_merge($this->_buildTriggerVariables($object), $extraVariables);

        $template = $trigger->getTemplate();
        if (!$template) {
            Courier::$plugin->log->logSend($trigger->uid, (string) $trigger->handle, null, '', '', 'failed', 'Trigger has no linked template.');
            return false;
        }

        $channelUids = $trigger->getChannelUids();
        if (empty($channelUids)) {
            return false;
        }

        $serializable = $this->_makeSerializable($variables);

        foreach ($channelUids as $channelUid) {
            Queue::push(new SendCourierJob([
                'triggerUid' => $trigger->uid,
                'templateHandle' => (string) $template->handle,
                'channelUid' => $channelUid,
                'recipients' => $trigger->recipients,
                'cc' => $trigger->cc,
                'bcc' => $trigger->bcc,
                'sendMode' => $trigger->sendMode ?: 'list',
                'variables' => $serializable,
            ]));
        }

        return true;
    }

    private function _shouldSkipEvent(Event $event): bool
    {
        $sender = $event->sender ?? null;
        if ($sender instanceof ElementInterface) {
            if (method_exists($sender, 'getIsDraft') && $sender->getIsDraft()) {
                return true;
            }
            if (method_exists($sender, 'getIsRevision') && $sender->getIsRevision()) {
                return true;
            }
        }
        return Craft::$app->getProjectConfig()->getIsApplyingExternalChanges();
    }

    private function _buildTriggerVariables(mixed $object): array
    {
        $variables = ['object' => $object];
        if ($object instanceof ElementInterface) {
            $short = strtolower((new \ReflectionClass($object))->getShortName());
            $variables[$short] = $object;
        }
        return $variables;
    }

    private function _makeSerializable(array $variables): array
    {
        $result = [];
        foreach ($variables as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $result[$key] = $value;
            } elseif (is_array($value)) {
                $result[$key] = $this->_makeSerializable($value);
            } elseif ($value instanceof \craft\base\ElementInterface) {
                // Store element references as IDs for re-querying in the job
                $result[$key] = [
                    '__elementType' => get_class($value),
                    '__elementId' => $value->id,
                ];
            } else {
                // Skip non-serializable values
                Craft::warning("Skipping non-serializable variable '{$key}' in trigger variables", __METHOD__);
            }
        }
        return $result;
    }
}
