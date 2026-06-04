<?php

namespace yellowrobot\courier\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use yellowrobot\courier\channels\ChannelTypeInterface;
use yellowrobot\courier\channels\types\DiscordChannelType;
use yellowrobot\courier\channels\types\EmailChannelType;
use yellowrobot\courier\channels\types\SlackChannelType;
use yellowrobot\courier\channels\types\SmsChannelType;
use yellowrobot\courier\channels\types\SmtpEmailChannelType;
use yellowrobot\courier\channels\types\WebhookChannelType;
use craft\events\RegisterComponentTypesEvent;
use yellowrobot\courier\models\ChannelConfig;
use yellowrobot\courier\records\ChannelConfigRecord;

/**
 * Registry of channel types + CRUD for user-configured channel instances.
 */
class Channels extends Component
{
    public const EVENT_REGISTER_CHANNEL_TYPES = 'registerChannelTypes';

    /** @var array<string,ChannelTypeInterface>|null */
    private ?array $types = null;

    /**
     * All available channel types, keyed by handle.
     *
     * @return array<string,ChannelTypeInterface>
     */
    public function getAllTypes(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $classes = [
            EmailChannelType::class,
            SmtpEmailChannelType::class,
            SlackChannelType::class,
            DiscordChannelType::class,
            WebhookChannelType::class,
            SmsChannelType::class,
        ];

        $event = new RegisterComponentTypesEvent(['types' => $classes]);
        $this->trigger(self::EVENT_REGISTER_CHANNEL_TYPES, $event);

        $this->types = [];
        foreach ($event->types as $class) {
            /** @var ChannelTypeInterface $type */
            $type = new $class();
            $this->types[$type->getHandle()] = $type;
        }

        return $this->types;
    }

    public function getTypeByHandle(string $handle): ?ChannelTypeInterface
    {
        return $this->getAllTypes()[$handle] ?? null;
    }

    /** @return array<int,array{label:string,value:string}> */
    public function getTypeOptions(): array
    {
        $options = [];
        foreach ($this->getAllTypes() as $handle => $type) {
            $options[] = ['label' => $type->getName(), 'value' => $handle];
        }
        return $options;
    }

    // -- Config CRUD --------------------------------------------------------

    /** @return ChannelConfig[] */
    public function getAllConfigs(): array
    {
        return array_map(
            fn(ChannelConfigRecord $r) => $this->configFromRecord($r),
            ChannelConfigRecord::find()->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])->all(),
        );
    }

    /** @return ChannelConfig[] */
    public function getEnabledConfigs(): array
    {
        return array_filter($this->getAllConfigs(), fn(ChannelConfig $c) => $c->enabled);
    }

    public function getConfigById(int $id): ?ChannelConfig
    {
        $record = ChannelConfigRecord::findOne($id);
        return $record ? $this->configFromRecord($record) : null;
    }

    public function getConfigByUid(string $uid): ?ChannelConfig
    {
        $record = ChannelConfigRecord::findOne(['uid' => $uid]);
        return $record ? $this->configFromRecord($record) : null;
    }

    /**
     * Options for a channel multi-select on a trigger (value = uid).
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function getConfigOptions(): array
    {
        $options = [];
        foreach ($this->getEnabledConfigs() as $config) {
            $type = $config->getChannelType();
            $typeLabel = $type ? $type->getName() : $config->type;
            $options[] = ['label' => "{$config->name} ({$typeLabel})", 'value' => $config->uid];
        }
        return $options;
    }

    public function saveConfig(ChannelConfig $config): bool
    {
        if (!$config->validate()) {
            return false;
        }

        $type = $config->getChannelType();
        if ($type) {
            $errors = $type->validateSettings($config->settings);
            if (!empty($errors)) {
                $config->addError('settings', implode(' ', $errors));
                return false;
            }
        }

        $record = $config->id
            ? ChannelConfigRecord::findOne($config->id)
            : new ChannelConfigRecord();

        if (!$record) {
            return false;
        }

        $record->name = $config->name;
        $record->handle = $config->handle;
        $record->type = $config->type;
        $record->settings = $config->settings ? Json::encode($config->settings) : null;
        $record->enabled = $config->enabled;
        $record->sortOrder = $config->sortOrder;

        if (!$record->save()) {
            $config->addErrors($record->getErrors());
            return false;
        }

        $config->id = $record->id;
        $config->uid = $record->uid;

        return true;
    }

    public function deleteConfig(ChannelConfig $config): bool
    {
        if (!$config->id) {
            return false;
        }
        $record = ChannelConfigRecord::findOne($config->id);
        return $record ? (bool) $record->delete() : false;
    }

    /**
     * Deliver a rendered message via a channel config.
     *
     * @param array{to:string|string[],cc?:string[],bcc?:string[],subject:string,html:string,text:string} $message
     * @return array{success:bool,recipient:string,error:?string}
     */
    public function send(ChannelConfig $config, array $message): array
    {
        $type = $config->getChannelType();
        if (!$type) {
            return ['success' => false, 'recipient' => '', 'error' => "Unknown channel type '{$config->type}'."];
        }
        return $type->send($config, $message);
    }

    /**
     * Seed a default Craft mailer email channel (used on install).
     */
    public function ensureDefaultEmailChannel(): void
    {
        if (ChannelConfigRecord::find()->where(['type' => 'email'])->exists()) {
            return;
        }
        $record = new ChannelConfigRecord();
        $record->name = 'Craft Email';
        $record->handle = 'craftEmail';
        $record->type = 'email';
        $record->settings = null;
        $record->enabled = true;
        $record->sortOrder = 1;
        $record->save();
    }

    private function configFromRecord(ChannelConfigRecord $record): ChannelConfig
    {
        $settings = [];
        if ($record->settings) {
            try {
                $settings = Json::decode($record->settings) ?: [];
            } catch (\Throwable) {
                $settings = [];
            }
        }

        return new ChannelConfig([
            'id' => (int) $record->id,
            'name' => $record->name,
            'handle' => $record->handle,
            'type' => $record->type,
            'settings' => $settings,
            'enabled' => (bool) $record->enabled,
            'sortOrder' => $record->sortOrder !== null ? (int) $record->sortOrder : null,
            'uid' => $record->uid,
        ]);
    }
}
