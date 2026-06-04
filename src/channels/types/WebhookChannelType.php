<?php

namespace yellowrobot\courier\channels\types;

use Craft;
use craft\helpers\App;
use craft\helpers\Cp;
use craft\helpers\Json;
use yellowrobot\courier\channels\BaseChannelType;
use yellowrobot\courier\models\ChannelConfig;

class WebhookChannelType extends BaseChannelType
{
    public static function handle(): string
    {
        return 'webhook';
    }

    public static function displayName(): string
    {
        return Craft::t('courier', 'Webhook');
    }

    public function supportsHtml(): bool
    {
        // Forwards subject/html/text in its JSON payload; the receiver decides.
        return true;
    }

    public function getSettingsHtml(ChannelConfig $config): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => 'URL',
            'instructions' => 'Endpoint to POST/PUT the payload to. Environment variables supported.',
            'name' => 'settings[url]',
            'value' => $config->settings['url'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::selectFieldHtml([
            'label' => 'Method',
            'name' => 'settings[method]',
            'value' => $config->settings['method'] ?? 'POST',
            'options' => [
                ['label' => 'POST', 'value' => 'POST'],
                ['label' => 'PUT', 'value' => 'PUT'],
            ],
        ]) . Cp::textareaFieldHtml([
            'label' => 'Headers',
            'instructions' => 'Optional JSON object of extra headers, e.g. `{"Authorization":"Bearer $TOKEN"}`.',
            'name' => 'settings[headers]',
            'value' => $config->settings['headers'] ?? '',
            'rows' => 3,
        ]);
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        if (empty($settings['url'])) {
            $errors[] = 'URL is required.';
        }
        if (!empty($settings['headers'])) {
            try {
                $decoded = Json::decode($settings['headers']);
                if (!is_array($decoded)) {
                    $errors[] = 'Headers must be a JSON object.';
                }
            } catch (\Throwable) {
                $errors[] = 'Headers is not valid JSON.';
            }
        }
        return $errors;
    }

    public function send(ChannelConfig $config, array $message): array
    {
        $recipient = $this->recipientLabel($message);
        $url = App::parseEnv($config->getSetting('url'));

        if (!$url) {
            return $this->fail($recipient, 'No webhook URL configured.');
        }

        $method = strtoupper($config->getSetting('method', 'POST'));
        if (!in_array($method, ['POST', 'PUT'], true)) {
            $method = 'POST';
        }

        $headers = [];
        if ($raw = $config->settings['headers'] ?? null) {
            try {
                $decoded = Json::decode($raw);
                foreach (is_array($decoded) ? $decoded : [] as $k => $v) {
                    $headers[$k] = is_string($v) ? \craft\helpers\App::parseEnv($v) : $v;
                }
            } catch (\Throwable) {
                // ignore — validated at save time
            }
        }

        $payload = [
            'subject' => $message['subject'] ?? '',
            'html' => $message['html'] ?? '',
            'text' => $message['text'] ?? '',
            'to' => $message['to'] ?? null,
        ];

        try {
            Craft::createGuzzleClient()->request($method, $url, [
                'json' => $payload,
                'headers' => $headers,
                'timeout' => 10,
            ]);
            return $this->ok($recipient);
        } catch (\Throwable $e) {
            return $this->fail($recipient, $e->getMessage());
        }
    }
}
