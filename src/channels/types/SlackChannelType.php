<?php

namespace yellowrobot\courier\channels\types;

use Craft;
use craft\helpers\App;
use craft\helpers\Cp;
use yellowrobot\courier\channels\BaseChannelType;
use yellowrobot\courier\models\ChannelConfig;

class SlackChannelType extends BaseChannelType
{
    public static function handle(): string
    {
        return 'slack';
    }

    public static function displayName(): string
    {
        return Craft::t('courier', 'Slack');
    }

    public function getSettingsHtml(ChannelConfig $config): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => 'Webhook URL',
            'instructions' => 'Slack incoming webhook URL, which posts to a single Slack channel and supports environment variables, e.g. `$SLACK_WEBHOOK_URL`.',
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
        $recipient = $config->name ?: 'slack';
        $url = App::parseEnv($config->getSetting('webhookUrl'));

        if (!$url) {
            return $this->fail($recipient, 'No Slack webhook URL configured.');
        }

        $subject = $message['subject'] ?? '';
        $text = $message['text'] ?? '';
        $body = $subject !== '' ? "*{$subject}*\n\n{$text}" : $text;

        $payload = ['text' => $body];

        try {
            Craft::createGuzzleClient()->post($url, ['json' => $payload, 'timeout' => 10]);
            return $this->ok($recipient);
        } catch (\Throwable $e) {
            return $this->fail($recipient, $e->getMessage());
        }
    }
}
