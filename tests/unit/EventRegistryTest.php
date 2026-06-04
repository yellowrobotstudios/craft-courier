<?php

namespace yellowrobot\courier\tests\unit;

use craft\elements\Entry;
use craft\elements\User;
use PHPUnit\Framework\TestCase;
use yellowrobot\courier\events\RegisterEventTriggersEvent;
use yellowrobot\courier\services\EventRegistry;

class EventRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        $this->stubPlugins(commerceInstalled: false);
    }

    /** Stub the plugins service so isCommerceInstalled() works without Craft. */
    private function stubPlugins(bool $commerceInstalled): void
    {
        \Craft::$app->set('plugins', new class($commerceInstalled) {
            public function __construct(private bool $installed)
            {
            }

            public function isPluginInstalled(string $handle): bool
            {
                return $this->installed;
            }

            public function isPluginEnabled(string $handle): bool
            {
                return $this->installed;
            }
        });
    }

    // ─── Core registry ───────────────────────────────────────

    public function testCoreTriggersArePresent(): void
    {
        $all = (new EventRegistry())->getAll();

        $expected = [
            'entry.created', 'entry.updated', 'entry.saved', 'entry.deleted',
            'user.created', 'user.updated', 'user.deleted', 'user.activated',
            'user.emailVerified', 'user.addedToGroup', 'user.removedFromGroup',
            'user.suspended', 'user.unsuspended',
            'asset.created', 'asset.updated', 'asset.deleted',
            'category.saved', 'category.deleted',
        ];

        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $all, "Registry missing trigger key: {$key}");
        }
    }

    public function testEveryTriggerHasRequiredShape(): void
    {
        foreach ((new EventRegistry())->getAll() as $key => $trigger) {
            $this->assertNotEmpty($trigger['label'] ?? null, "{$key} missing label");
            $this->assertNotEmpty($trigger['category'] ?? null, "{$key} missing category");
            $this->assertNotEmpty($trigger['class'] ?? null, "{$key} missing class");
            $this->assertNotEmpty($trigger['event'] ?? null, "{$key} missing event");
        }
    }

    public function testCreatedAndUpdatedConditionsGateOnIsNew(): void
    {
        $registry = new EventRegistry();

        $newEvent = new \craft\events\ModelEvent(['isNew' => true]);
        $existingEvent = new \craft\events\ModelEvent(['isNew' => false]);

        $created = $registry->get('entry.created');
        $updated = $registry->get('entry.updated');

        $this->assertTrue(($created['condition'])($newEvent));
        $this->assertFalse(($created['condition'])($existingEvent));
        $this->assertFalse(($updated['condition'])($newEvent));
        $this->assertTrue(($updated['condition'])($existingEvent));
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $this->assertNull((new EventRegistry())->get('nope.never'));
    }

    // ─── Commerce gating ─────────────────────────────────────

    public function testCommerceTriggersAbsentWhenNotInstalled(): void
    {
        $all = (new EventRegistry())->getAll();

        $this->assertArrayNotHasKey('commerce.order.completed', $all);
        $this->assertArrayNotHasKey('commerce.subscription.created', $all);
    }

    public function testCommerceTriggersPresentWhenInstalled(): void
    {
        $this->stubPlugins(commerceInstalled: true);
        $all = (new EventRegistry())->getAll();

        foreach ([
            'commerce.order.completed',
            'commerce.order.paid',
            'commerce.order.statusChanged',
            'commerce.subscription.created',
            'commerce.subscription.canceled',
        ] as $key) {
            $this->assertArrayHasKey($key, $all, "Registry missing Commerce trigger: {$key}");
        }
    }

    // ─── Extensibility ───────────────────────────────────────

    public function testModulesCanRegisterCustomTriggers(): void
    {
        $registry = new EventRegistry();
        $registry->on(
            EventRegistry::EVENT_REGISTER_EVENT_TRIGGERS,
            function (RegisterEventTriggersEvent $event) {
                $event->triggers['custom.thing'] = [
                    'label' => 'Thing happened',
                    'category' => 'Custom',
                    'class' => 'modules\\Foo',
                    'event' => 'afterThing',
                ];
            }
        );

        $this->assertArrayHasKey('custom.thing', $registry->getAll());
        $this->assertSame('Thing happened', $registry->get('custom.thing')['label']);
    }

    // ─── Element type resolution ─────────────────────────────

    public function testGetElementTypeUsesExplicitElementType(): void
    {
        $this->assertSame(Entry::class, (new EventRegistry())->getElementType('entry.created'));
        $this->assertSame(User::class, (new EventRegistry())->getElementType('user.activated'));
    }

    public function testGetElementTypeFallsBackToElementClass(): void
    {
        $registry = new EventRegistry();
        $registry->on(
            EventRegistry::EVENT_REGISTER_EVENT_TRIGGERS,
            function (RegisterEventTriggersEvent $event) {
                // Element class, no explicit elementType → falls back to the class
                $event->triggers['custom.entryThing'] = [
                    'label' => 'X',
                    'category' => 'Custom',
                    'class' => Entry::class,
                    'event' => 'afterX',
                ];
                // Non-element class, no elementType → null
                $event->triggers['custom.serviceThing'] = [
                    'label' => 'Y',
                    'category' => 'Custom',
                    'class' => \craft\services\Users::class,
                    'event' => 'afterY',
                ];
            }
        );

        $this->assertSame(Entry::class, $registry->getElementType('custom.entryThing'));
        $this->assertNull($registry->getElementType('custom.serviceThing'));
        $this->assertNull($registry->getElementType('nope.never'));
    }

    // ─── Grouped options ─────────────────────────────────────

    public function testGroupedOptionsQualifyLabelsWithNoun(): void
    {
        $options = (new EventRegistry())->getGroupedOptions();

        $optgroups = array_column(array_filter($options, fn($o) => isset($o['optgroup'])), 'optgroup');
        $this->assertContains('Entries', $optgroups);
        $this->assertContains('Users', $optgroups);

        $labels = array_column(array_filter($options, fn($o) => isset($o['value'])), 'label', 'value');
        $this->assertSame('Entry created', $labels['entry.created']);
        $this->assertSame('User activated', $labels['user.activated']);
    }

    public function testRegistryIsCachedPerInstance(): void
    {
        $registry = new EventRegistry();
        $calls = 0;
        $registry->on(
            EventRegistry::EVENT_REGISTER_EVENT_TRIGGERS,
            function () use (&$calls) {
                $calls++;
            }
        );

        $registry->getAll();
        $registry->getAll();

        $this->assertSame(1, $calls, 'Register event should only fire once per instance');
    }
}
