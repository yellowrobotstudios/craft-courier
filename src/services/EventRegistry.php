<?php

namespace yellowrobot\courier\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\services\Users;
use yellowrobot\courier\events\RegisterEventTriggersEvent;

/**
 * Curated registry of event triggers. Each entry maps a `triggerKey`
 * (e.g. `entry.created`) to the class/event that fires it, plus optional
 * runtime helpers:
 *
 *  - `condition`       fn($event): bool  — built-in gate (e.g. isNew)
 *  - `objectExtractor` fn($event): mixed — pull the bound object off the event
 *                                          (defaults to $event->sender)
 *  - `elementType`     class-string      — element type for the condition
 *                                          builder + preview scoping
 *
 * Extensible via RegisterEventTriggersEvent.
 */
class EventRegistry extends Component
{
    public const EVENT_REGISTER_EVENT_TRIGGERS = 'registerEventTriggers';

    /** @var array<string,array<string,mixed>>|null */
    private ?array $triggers = null;

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getAll(): array
    {
        if ($this->triggers !== null) {
            return $this->triggers;
        }

        $triggers = [
            // -- Entries --
            'entry.created' => [
                'label' => 'Created',
                'category' => 'Entries',
                'class' => Entry::class,
                'event' => Element::EVENT_AFTER_SAVE,
                'elementType' => Entry::class,
                'condition' => fn($e) => $e->isNew,
            ],
            'entry.updated' => [
                'label' => 'Updated',
                'category' => 'Entries',
                'class' => Entry::class,
                'event' => Element::EVENT_AFTER_SAVE,
                'elementType' => Entry::class,
                'condition' => fn($e) => !$e->isNew,
            ],
            'entry.saved' => [
                'label' => 'Saved (created or updated)',
                'category' => 'Entries',
                'class' => Entry::class,
                'event' => Element::EVENT_AFTER_SAVE,
                'elementType' => Entry::class,
            ],
            'entry.deleted' => [
                'label' => 'Deleted',
                'category' => 'Entries',
                'class' => Entry::class,
                'event' => Element::EVENT_BEFORE_DELETE,
                'elementType' => Entry::class,
            ],

            // -- Users --
            'user.created' => [
                'label' => 'Created',
                'category' => 'Users',
                'class' => User::class,
                'event' => Element::EVENT_AFTER_SAVE,
                'elementType' => User::class,
                'condition' => fn($e) => $e->isNew,
            ],
            'user.updated' => [
                'label' => 'Updated',
                'category' => 'Users',
                'class' => User::class,
                'event' => Element::EVENT_AFTER_SAVE,
                'elementType' => User::class,
                'condition' => fn($e) => !$e->isNew,
            ],
            'user.deleted' => [
                'label' => 'Deleted',
                'category' => 'Users',
                'class' => User::class,
                'event' => Element::EVENT_BEFORE_DELETE,
                'elementType' => User::class,
            ],
            'user.activated' => [
                'label' => 'Activated',
                'category' => 'Users',
                'class' => Users::class,
                'event' => Users::EVENT_AFTER_ACTIVATE_USER,
                'elementType' => User::class,
                'objectExtractor' => fn($e) => $e->user,
            ],
            'user.emailVerified' => [
                'label' => 'Email verified',
                'category' => 'Users',
                'class' => Users::class,
                'event' => Users::EVENT_AFTER_VERIFY_EMAIL,
                'elementType' => User::class,
                'objectExtractor' => fn($e) => $e->user,
            ],
            // The assign event fires only when membership actually changes (core
            // early-returns on a no-op), and carries the delta — newGroupIds /
            // removedGroupIds. We split it into two triggers and gate each side so
            // "added" never fires on a pure removal (and vice versa).
            'user.addedToGroup' => [
                'label' => 'Added to group(s)',
                'category' => 'Users',
                'class' => Users::class,
                'event' => Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS,
                'elementType' => User::class,
                'condition' => fn($e) => !empty($e->newGroupIds),
                // The event carries userId/groupIds, not a User element — resolve it.
                'objectExtractor' => fn($e) => Craft::$app->getUsers()->getUserById($e->userId),
            ],
            'user.removedFromGroup' => [
                'label' => 'Removed from group(s)',
                'category' => 'Users',
                'class' => Users::class,
                'event' => Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS,
                'elementType' => User::class,
                'condition' => fn($e) => !empty($e->removedGroupIds),
                'objectExtractor' => fn($e) => Craft::$app->getUsers()->getUserById($e->userId),
            ],
            'user.suspended' => [
                'label' => 'Suspended',
                'category' => 'Users',
                'class' => Users::class,
                'event' => Users::EVENT_AFTER_SUSPEND_USER,
                'elementType' => User::class,
                'objectExtractor' => fn($e) => $e->user,
            ],
            'user.unsuspended' => [
                'label' => 'Unsuspended',
                'category' => 'Users',
                'class' => Users::class,
                'event' => Users::EVENT_AFTER_UNSUSPEND_USER,
                'elementType' => User::class,
                'objectExtractor' => fn($e) => $e->user,
            ],

            // -- Assets --
            'asset.created' => [
                'label' => 'Created',
                'category' => 'Assets',
                'class' => Asset::class,
                'event' => Element::EVENT_AFTER_SAVE,
                'elementType' => Asset::class,
                'condition' => fn($e) => $e->isNew,
            ],
            'asset.updated' => [
                'label' => 'Updated',
                'category' => 'Assets',
                'class' => Asset::class,
                'event' => Element::EVENT_AFTER_SAVE,
                'elementType' => Asset::class,
                'condition' => fn($e) => !$e->isNew,
            ],
            'asset.deleted' => [
                'label' => 'Deleted',
                'category' => 'Assets',
                'class' => Asset::class,
                'event' => Element::EVENT_BEFORE_DELETE,
                'elementType' => Asset::class,
            ],

            // -- Categories --
            'category.saved' => [
                'label' => 'Saved',
                'category' => 'Categories',
                'class' => Category::class,
                'event' => Element::EVENT_AFTER_SAVE,
                'elementType' => Category::class,
            ],
            'category.deleted' => [
                'label' => 'Deleted',
                'category' => 'Categories',
                'class' => Category::class,
                'event' => Element::EVENT_BEFORE_DELETE,
                'elementType' => Category::class,
            ],
        ];

        if ($this->isCommerceInstalled()) {
            $triggers = array_merge($triggers, $this->commerceTriggers());
        }

        $event = new RegisterEventTriggersEvent(['triggers' => $triggers]);
        $this->trigger(self::EVENT_REGISTER_EVENT_TRIGGERS, $event);

        return $this->triggers = $event->triggers;
    }

    public function get(string $key): ?array
    {
        return $this->getAll()[$key] ?? null;
    }

    /**
     * Options for a grouped <select> (optgroups by category).
     *
     * @return array<int,array<string,string>>
     */
    public function getGroupedOptions(): array
    {
        $byCategory = [];
        foreach ($this->getAll() as $key => $trigger) {
            $byCategory[$trigger['category'] ?? 'Other'][] = ['value' => $key, 'label' => $trigger['label']];
        }

        $options = [];
        foreach ($byCategory as $category => $entries) {
            $options[] = ['optgroup' => $category];
            $noun = $this->categoryNoun($category);
            foreach ($entries as $entry) {
                // Qualify the label with its noun so the selected value keeps its
                // context when collapsed (the optgroup header isn't visible then) —
                // e.g. "Entry created", "User activated", "Order status changed".
                $entry['label'] = $noun !== '' ? $noun . ' ' . lcfirst($entry['label']) : $entry['label'];
                $options[] = $entry;
            }
        }
        return $options;
    }

    /** Singular noun for a category, e.g. "Commerce Orders" → "Order". */
    private function categoryNoun(string $category): string
    {
        $words = explode(' ', trim($category));
        $last = (string) end($words);
        return $last === '' || $last === 'Other' ? '' : \yii\helpers\Inflector::singularize($last);
    }

    /**
     * The element type a trigger is bound to (for the condition builder + preview).
     */
    public function getElementType(string $key): ?string
    {
        $trigger = $this->get($key);
        if (!$trigger) {
            return null;
        }
        if (!empty($trigger['elementType'])) {
            return $trigger['elementType'];
        }
        $class = $trigger['class'] ?? null;
        if ($class && is_subclass_of($class, Element::class)) {
            return $class;
        }
        return null;
    }

    private function isCommerceInstalled(): bool
    {
        return Craft::$app->plugins->isPluginInstalled('commerce')
            && Craft::$app->plugins->isPluginEnabled('commerce');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function commerceTriggers(): array
    {
        $orderClass = 'craft\\commerce\\elements\\Order';
        $ordersService = 'craft\\commerce\\services\\Orders';
        $orderHistoriesService = 'craft\\commerce\\services\\OrderHistories';
        $subscriptionClass = 'craft\\commerce\\elements\\Subscription';
        $subscriptionsService = 'craft\\commerce\\services\\Subscriptions';

        return [
            'commerce.order.completed' => [
                'label' => 'Completed',
                'category' => 'Commerce Orders',
                'class' => $orderClass,
                'event' => 'afterCompleteOrder',
                'elementType' => $orderClass,
            ],
            'commerce.order.paid' => [
                'label' => 'Paid',
                'category' => 'Commerce Orders',
                'class' => $ordersService,
                'event' => 'afterOrderPaid',
                'elementType' => $orderClass,
                'objectExtractor' => fn($e) => $e->order,
            ],
            // The canonical "your order shipped / is ready" trigger. OrderStatusEvent
            // carries both ->order and ->orderHistory (the new status, with a message).
            'commerce.order.statusChanged' => [
                'label' => 'Status changed',
                'category' => 'Commerce Orders',
                'class' => $orderHistoriesService,
                'event' => 'orderStatusChange',
                'elementType' => $orderClass,
                'objectExtractor' => fn($e) => $e->order,
            ],
            // SubscriptionEvent binds the element to $event->subscription, not $event->sender
            // (the sender is the Subscriptions service) — the objectExtractor fixes that.
            'commerce.subscription.created' => [
                'label' => 'Created',
                'category' => 'Commerce Subscriptions',
                'class' => $subscriptionsService,
                'event' => 'afterCreateSubscription',
                'elementType' => $subscriptionClass,
                'objectExtractor' => fn($e) => $e->subscription,
            ],
            'commerce.subscription.canceled' => [
                'label' => 'Canceled',
                'category' => 'Commerce Subscriptions',
                'class' => $subscriptionsService,
                'event' => 'afterCancelSubscription',
                'elementType' => $subscriptionClass,
                'objectExtractor' => fn($e) => $e->subscription,
            ],
        ];
    }
}
