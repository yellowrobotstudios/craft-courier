<?php

namespace yellowrobot\courier;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yellowrobot\courier\elements\EmailTemplate;
use yellowrobot\courier\elements\Trigger;
use yellowrobot\courier\models\Settings;
use yellowrobot\courier\services\Channels;
use yellowrobot\courier\services\EmailService;
use yellowrobot\courier\services\EventRegistry;
use yellowrobot\courier\services\HookService;
use yellowrobot\courier\services\LogService;
use yellowrobot\courier\services\Scheduler;
use yii\base\Event;

/**
 * Courier - Template management and event-driven sending for Craft CMS
 *
 * @property-read Settings $settings
 * @property-read HookService $hook
 * @property-read EmailService $email
 * @property-read LogService $log
 * @property-read Channels $channels
 * @property-read EventRegistry $events
 * @property-read Scheduler $scheduler
 */
class Courier extends Plugin
{
    public static Courier $plugin;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'hook' => HookService::class,
            'email' => EmailService::class,
            'log' => LogService::class,
            'channels' => Channels::class,
            'events' => EventRegistry::class,
            'scheduler' => Scheduler::class,
        ]);

        // Element types and log GC must register on every request (front-end
        // sends, queue, console). CP routes and the permissions UI only matter
        // for control-panel requests, so guard them to keep front-end boot lean.
        $this->_registerElementTypes();
        $this->_registerGarbageCollection();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpRoutes();
            $this->_registerPermissions();
        }

        Craft::$app->onInit(function () {
            if (!$this->isInstalled) {
                return;
            }
            $this->hook->registerTriggerListeners();
        });

        Craft::info('Courier plugin loaded', __METHOD__);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('courier', 'Courier');
        $item['subnav'] = [
            'triggers' => ['label' => Craft::t('courier', 'Triggers'), 'url' => 'courier/triggers'],
            'channels' => ['label' => Craft::t('courier', 'Channels'), 'url' => 'courier/channels'],
            'logs' => ['label' => Craft::t('courier', 'Logs'), 'url' => 'courier/logs'],
        ];

        // Surface delivery failures the user hasn't seen yet — on the plugin item
        // (visible from anywhere in the CP) and the Logs subnav item (points at
        // where to look). Opening the Logs screen clears it.
        if ($this->isInstalled) {
            $failed = $this->log->getUnseenFailedCount();
            if ($failed > 0) {
                $item['badgeCount'] = $failed;
                $item['subnav']['logs']['badgeCount'] = $failed;
            }
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = EmailTemplate::class;
                $event->types[] = Trigger::class;
            }
        );
    }



    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['courier'] = 'courier/triggers/index';
                $event->rules['courier/edit/<elementId:\d+>'] = 'elements/edit';
                $event->rules['courier/triggers'] = 'courier/triggers/index';
                $event->rules['courier/triggers/new'] = 'courier/triggers/create';
                $event->rules['courier/triggers/condition-builder'] = 'courier/triggers/condition-builder';
                $event->rules['courier/triggers/edit/<elementId:\d+>'] = 'elements/edit';
                $event->rules['courier/channels'] = 'courier/channels/index';
                $event->rules['courier/channels/new'] = 'courier/channels/edit';
                $event->rules['courier/channels/<id:\d+>'] = 'courier/channels/edit';
                $event->rules['courier/logs'] = 'courier/logs/index';
                $event->rules['courier/logs/resend'] = 'courier/logs/resend';
                $event->rules['courier/logs/<id:\d+>'] = 'courier/logs/detail';
            }
        );
    }

    /**
     * Prune logs older than the configured retention window during Craft's
     * normal garbage collection. No-op when retention isn't set.
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function () {
            $days = (int) ($this->getSettings()->logRetentionDays ?? 0);
            if ($days > 0) {
                $this->log->pruneOlderThan($days);
            }
        });
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('courier', 'Courier'),
                    'permissions' => [
                        // Admin-trust: full wiring (triggers, channels, conditions, Twig).
                        'courier:manage' => [
                            'label' => Craft::t('courier', 'Manage triggers'),
                        ],
                        // Lower-trust content role. Reserved for the standalone
                        // template-copy editor (a planned slice); today templates
                        // are edited inline on the trigger screen under :manage.
                        'courier:edit-templates' => [
                            'label' => Craft::t('courier', 'Edit template content'),
                        ],
                    ],
                ];
            }
        );
    }
}
