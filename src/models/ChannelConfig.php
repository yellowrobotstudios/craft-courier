<?php

namespace yellowrobot\courier\models;

use craft\base\Model;
use craft\helpers\App;
use yellowrobot\courier\channels\ChannelTypeInterface;
use yellowrobot\courier\Courier;

class ChannelConfig extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $type = '';
    /** @var array<string,mixed> */
    public array $settings = [];
    public bool $enabled = true;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    public function getChannelType(): ?ChannelTypeInterface
    {
        return Courier::$plugin->channels->getTypeByHandle($this->type);
    }

    /** Resolve a setting value, parsing environment variables. */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? $default;
        return is_string($value) ? App::parseEnv($value) : $value;
    }

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'type'], 'required'],
            [['name', 'handle', 'type'], 'string', 'max' => 255],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, and underscores.'],
            [['enabled'], 'boolean'],
            [['settings'], 'safe'],
        ];
    }
}
