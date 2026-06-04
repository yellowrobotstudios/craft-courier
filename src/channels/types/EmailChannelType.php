<?php

namespace yellowrobot\courier\channels\types;

use Craft;
use craft\helpers\Html;
use craft\mail\Message;
use yellowrobot\courier\channels\BaseChannelType;
use yellowrobot\courier\models\ChannelConfig;

/**
 * Sends through Craft's own mailer, using Craft's configured email settings
 * (System Email Address + Sender Name, under Settings → Email). Pure transport:
 * recipients (To/Cc/Bcc) live on the trigger, not here. To send via a different
 * provider, use the SMTP channel instead.
 */
class EmailChannelType extends BaseChannelType
{
    public static function handle(): string
    {
        return 'email';
    }

    public static function displayName(): string
    {
        return Craft::t('courier', 'Craft Email');
    }

    public function hasSubject(): bool
    {
        return true;
    }

    public function supportsHtml(): bool
    {
        return true;
    }

    public function getSettingsHtml(ChannelConfig $config): string
    {
        // Pure transport: no per-channel config. Recipients live on the trigger,
        // and the From address/name come from Craft's system email settings.
        return Html::tag(
            'p',
            'Uses Craft’s configured system email settings (Settings → Email). Recipients are set per trigger.',
            ['class' => 'light'],
        );
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    public function send(ChannelConfig $config, array $message): array
    {
        $recipient = $this->recipientLabel($message);

        try {
            $msg = new Message();
            $msg->setSubject($message['subject'] ?? '');
            $msg->setHtmlBody($message['html'] ?? '');
            if (!empty($message['text'])) {
                $msg->setTextBody($message['text']);
            }

            // No From handling here — Craft's mailer applies the system
            // From address/name. That's the whole point of "Craft Email".
            $msg->setTo($message['to']);
            if (!empty($message['cc'])) {
                $msg->setCc($message['cc']);
            }
            if (!empty($message['bcc'])) {
                $msg->setBcc($message['bcc']);
            }

            $sent = Craft::$app->getMailer()->send($msg);

            return $sent
                ? $this->ok($recipient)
                : $this->fail($recipient, 'Mailer returned false');
        } catch (\Throwable $e) {
            return $this->fail($recipient, $e->getMessage());
        }
    }
}
