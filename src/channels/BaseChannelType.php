<?php

namespace yellowrobot\courier\channels;

use craft\base\Component;
use yellowrobot\courier\models\ChannelConfig;

/**
 * Base class for channel types. Extends {@see Component} so types are first-class
 * Craft components (static {@see Component::displayName()} / {@see isSelectable()}),
 * while delivery settings remain on the per-instance {@see ChannelConfig}.
 */
abstract class BaseChannelType extends Component implements ChannelTypeInterface
{
    public static function isSelectable(): bool
    {
        return true;
    }

    public function getHandle(): string
    {
        return static::handle();
    }

    public function getName(): string
    {
        return static::displayName();
    }

    public function hasSubject(): bool
    {
        return false;
    }

    public function supportsHtml(): bool
    {
        return false;
    }

    public function getSettingsHtml(ChannelConfig $config): string
    {
        return '';
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    /** Normalize a `to` value (string or array) into a display string for logging. */
    protected function recipientLabel(array $message): string
    {
        $to = $message['to'] ?? '';
        return is_array($to) ? implode(', ', $to) : (string) $to;
    }

    /** @return array{success:bool,recipient:string,error:null} */
    protected function ok(string $recipient): array
    {
        return ['success' => true, 'recipient' => $recipient, 'error' => null];
    }

    /** @return array{success:bool,recipient:string,error:string} */
    protected function fail(string $recipient, string $error): array
    {
        return ['success' => false, 'recipient' => $recipient, 'error' => $error];
    }
}
