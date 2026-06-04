<?php

namespace yellowrobot\courier\channels\types;

use Craft;
use craft\helpers\App;
use craft\helpers\Cp;
use yellowrobot\courier\channels\BaseChannelType;
use yellowrobot\courier\models\ChannelConfig;

/**
 * Posts to a Discord channel via an incoming webhook. Like Slack, the webhook
 * URL determines the channel; the message is sent as Discord-flavored markdown.
 */
class DiscordChannelType extends BaseChannelType
{
    /** Discord rejects messages longer than 2000 characters. */
    private const MAX_CONTENT = 2000;

    public static function handle(): string
    {
        return 'discord';
    }

    public static function displayName(): string
    {
        return Craft::t('courier', 'Discord');
    }

    public function getSettingsHtml(ChannelConfig $config): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => 'Webhook URL',
            'instructions' => 'Discord webhook URL (Server Settings → Integrations → Webhooks), which posts to a single channel and supports environment variables, e.g. `$DISCORD_WEBHOOK_URL`.',
            'name' => 'settings[webhookUrl]',
            'value' => $config->settings['webhookUrl'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]);
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        if (empty($settings['webhookUrl'])) {
            $errors[] = 'Webhook URL is required.';
        }
        return $errors;
    }

    public function send(ChannelConfig $config, array $message): array
    {
        $recipient = $config->name ?: 'discord';
        $url = App::parseEnv($config->getSetting('webhookUrl'));

        if (!$url) {
            return $this->fail($recipient, 'No Discord webhook URL configured.');
        }

        $subject = $message['subject'] ?? '';
        $text = $message['text'] ?? '';
        $body = $subject !== '' ? "**{$subject}**\n\n{$text}" : $text;
        $body = mb_substr($body, 0, self::MAX_CONTENT);

        if ($body === '') {
            return $this->fail($recipient, 'Nothing to send (empty message body).');
        }

        try {
            Craft::createGuzzleClient()->post((string) $url, ['json' => ['content' => $body], 'timeout' => 10]);
            return $this->ok($recipient);
        } catch (\Throwable $e) {
            return $this->fail($recipient, $e->getMessage());
        }
    }
}
