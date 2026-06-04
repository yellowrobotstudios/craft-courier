<?php

namespace yellowrobot\courier\channels\types;

use craft\helpers\Cp;
use yellowrobot\courier\channels\BaseChannelType;
use yellowrobot\courier\channels\sms\SmsProviderInterface;
use yellowrobot\courier\channels\sms\SnsProvider;
use yellowrobot\courier\channels\sms\TwilioProvider;
use yellowrobot\courier\models\ChannelConfig;

class SmsChannelType extends BaseChannelType
{
    public static function handle(): string
    {
        return 'sms';
    }

    public static function displayName(): string
    {
        return \Craft::t('courier', 'SMS');
    }

    public function getSettingsHtml(ChannelConfig $config): string
    {
        // Transport config only — recipient phone numbers are set per trigger.
        return Cp::selectFieldHtml([
            'label' => 'Provider',
            'instructions' => 'Credentials are read from environment variables (Twilio: `TWILIO_SID`/`TWILIO_TOKEN`/`TWILIO_FROM`; SNS: AWS env vars). Recipient phone numbers are set per trigger.',
            'name' => 'settings[provider]',
            'value' => $config->settings['provider'] ?? 'twilio',
            'options' => [
                ['label' => 'Twilio', 'value' => 'twilio'],
                ['label' => 'AWS SNS', 'value' => 'sns'],
            ],
        ]);
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    public function send(ChannelConfig $config, array $message): array
    {
        $recipient = $this->recipientLabel($message);
        $subject = $message['subject'] ?? '';
        $text = $message['text'] ?? '';
        $body = $subject !== '' ? "{$subject}\n\n{$text}" : $text;

        try {
            $this->provider($config)->send((string) $message['to'], $body);
            return $this->ok($recipient);
        } catch (\Throwable $e) {
            return $this->fail($recipient, $e->getMessage());
        }
    }

    private function provider(ChannelConfig $config): SmsProviderInterface
    {
        return match ($config->getSetting('provider', 'twilio')) {
            'sns', 'aws' => new SnsProvider(),
            default => new TwilioProvider(),
        };
    }
}
