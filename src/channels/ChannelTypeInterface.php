<?php

namespace yellowrobot\courier\channels;

use craft\base\ComponentInterface;
use yellowrobot\courier\models\ChannelConfig;

/**
 * A ChannelType defines what a channel *is* and how it delivers a message.
 * Types ship with the plugin (Email/Slack/Webhook/SMS) or are registered by
 * third parties via the channel-types event. User-configured instances of a
 * type are stored as ChannelConfig records — settings live on the config, not
 * the type, so a single type backs many named channels (cf. Commerce gateways).
 *
 * Identity follows Craft's component convention: a class is identified by the
 * static {@see ComponentInterface::displayName()} (label) and {@see handle()}
 * (the stable string persisted on ChannelConfig::$type).
 */
interface ChannelTypeInterface extends ComponentInterface
{
    /** Stable identifier persisted on a config, e.g. `email`, `slack`. */
    public static function handle(): string;

    /** Instance accessor for {@see handle()} (kept for templates/type-hinted callers). */
    public function getHandle(): string;

    /** Instance accessor for {@see ComponentInterface::displayName()}. */
    public function getName(): string;

    /** Whether this channel uses a subject line (email yes; SMS/Slack no). */
    public function hasSubject(): bool;

    /** Whether this channel renders the HTML body (email/webhook) vs plain text only (SMS/Slack/Discord). */
    public function supportsHtml(): bool;

    /** Settings form HTML for a given config (field names namespaced under `settings[...]`). */
    public function getSettingsHtml(ChannelConfig $config): string;

    /**
     * Validate raw settings. Return a list of human-readable error strings (empty = valid).
     *
     * @param array<string,mixed> $settings
     * @return string[]
     */
    public function validateSettings(array $settings): array;

    /**
     * Deliver a rendered message.
     *
     * @param array{to:string|string[],cc?:string[],bcc?:string[],subject:string,html:string,text:string} $message
     * @return array{success:bool,recipient:string,error:?string}
     */
    public function send(ChannelConfig $config, array $message): array;
}
