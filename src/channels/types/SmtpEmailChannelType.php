<?php

namespace yellowrobot\courier\channels\types;

use craft\helpers\App;
use craft\helpers\Cp;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use yellowrobot\courier\channels\BaseChannelType;
use yellowrobot\courier\models\ChannelConfig;

/**
 * Sends email through an external SMTP server (SendGrid, Mailgun, Postmark,
 * Gmail, etc.) using its own transport — independent of Craft's mailer. Bring
 * your own host, credentials, and From address.
 */
class SmtpEmailChannelType extends BaseChannelType
{
    public static function handle(): string
    {
        return 'smtp';
    }

    public static function displayName(): string
    {
        return \Craft::t('courier', 'SMTP');
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
        // Transport config only — recipients (To/Cc/Bcc) are set per trigger.
        return Cp::autosuggestFieldHtml([
            'label' => 'Host',
            'instructions' => 'SMTP server hostname, e.g. `smtp.sendgrid.net`. Environment variables supported.',
            'name' => 'settings[host]',
            'value' => $config->settings['host'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::autosuggestFieldHtml([
            'label' => 'Port',
            'instructions' => 'Defaults to 587 (TLS) or 465 (SSL). Environment variables supported.',
            'name' => 'settings[port]',
            'value' => $config->settings['port'] ?? '',
            'suggestEnvVars' => true,
        ]) . Cp::selectizeFieldHtml([
            'label' => 'Encryption',
            'instructions' => 'Connection encryption. Defaults to TLS. Environment variables supported.',
            'name' => 'settings[encryption]',
            'value' => $config->settings['encryption'] ?? 'tls',
            'options' => [
                ['label' => 'TLS (STARTTLS)', 'value' => 'tls'],
                ['label' => 'SSL', 'value' => 'ssl'],
                ['label' => 'None', 'value' => 'none'],
            ],
            'includeEnvVars' => true,
        ]) . Cp::autosuggestFieldHtml([
            'label' => 'Username',
            'instructions' => 'Optional. Environment variables supported.',
            'name' => 'settings[username]',
            'value' => $config->settings['username'] ?? '',
            'suggestEnvVars' => true,
        ]) . Cp::autosuggestFieldHtml([
            'label' => 'Password',
            'instructions' => 'Store the password in an environment variable, e.g. `$SMTP_PASSWORD`.',
            'name' => 'settings[password]',
            'value' => $config->settings['password'] ?? '',
            'suggestEnvVars' => true,
        ]) . Cp::autosuggestFieldHtml([
            'label' => 'From email',
            'instructions' => 'The address this channel sends from. Environment variables supported.',
            'name' => 'settings[fromEmail]',
            'value' => $config->settings['fromEmail'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::autosuggestFieldHtml([
            'label' => 'From name',
            'instructions' => 'Optional. Environment variables supported.',
            'name' => 'settings[fromName]',
            'value' => $config->settings['fromName'] ?? '',
            'suggestEnvVars' => true,
        ]);
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        if (empty($settings['host'])) {
            $errors[] = 'SMTP host is required.';
        }
        if (empty($settings['fromEmail'])) {
            $errors[] = 'From email is required.';
        }
        $port = trim((string) ($settings['port'] ?? ''));
        if ($port !== '' && !str_starts_with($port, '$') && !ctype_digit($port)) {
            $errors[] = 'Port must be a number or an environment variable.';
        }
        return $errors;
    }

    public function send(ChannelConfig $config, array $message): array
    {
        $recipient = $this->recipientLabel($message);

        $host = (string) App::parseEnv($config->getSetting('host'));
        if ($host === '') {
            return $this->fail($recipient, 'No SMTP host configured.');
        }

        $fromEmail = (string) App::parseEnv($config->getSetting('fromEmail'));
        if ($fromEmail === '') {
            return $this->fail($recipient, 'No From email configured.');
        }

        $encryption = strtolower((string) App::parseEnv($config->getSetting('encryption', 'tls')));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }
        $port = (int) (App::parseEnv($config->getSetting('port'))
            ?: ($encryption === 'ssl' ? 465 : 587));
        $username = (string) App::parseEnv($config->getSetting('username'));
        $password = (string) App::parseEnv($config->getSetting('password'));
        $fromName = (string) App::parseEnv($config->getSetting('fromName'));

        try {
            $transport = new EsmtpTransport($host, $port, $encryption === 'ssl');
            if ($username !== '') {
                $transport->setUsername($username);
            }
            if ($password !== '') {
                $transport->setPassword($password);
            }

            $email = (new Email())
                ->from($fromName !== '' ? new Address($fromEmail, $fromName) : new Address($fromEmail))
                ->subject($message['subject'] ?? '')
                ->html($message['html'] ?? '');

            if (!empty($message['text'])) {
                $email->text($message['text']);
            }

            foreach ($this->addresses($message['to'] ?? []) as $to) {
                $email->addTo($to);
            }
            foreach ($this->addresses($message['cc'] ?? []) as $cc) {
                $email->addCc($cc);
            }
            foreach ($this->addresses($message['bcc'] ?? []) as $bcc) {
                $email->addBcc($bcc);
            }

            (new Mailer($transport))->send($email);
            return $this->ok($recipient);
        } catch (\Throwable $e) {
            return $this->fail($recipient, $e->getMessage());
        }
    }

    /**
     * Normalize a `to`/`cc`/`bcc` value (string or array) into a clean list.
     *
     * @return string[]
     */
    private function addresses(mixed $value): array
    {
        $list = is_array($value) ? $value : [$value];
        return array_values(array_filter(array_map('trim', array_map('strval', $list))));
    }
}
