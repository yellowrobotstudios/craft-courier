<?php

namespace yellowrobot\courier\channels\sms;

use craft\helpers\App;

class SnsProvider implements SmsProviderInterface
{
    public function send(string $to, string $body): void
    {
        if (!class_exists(\Aws\Sns\SnsClient::class)) {
            throw new \RuntimeException('AWS SDK not installed. Run: composer require aws/aws-sdk-php');
        }

        $region = App::env('AWS_REGION') ?: 'us-east-1';

        $sns = new \Aws\Sns\SnsClient([
            'version' => 'latest',
            'region' => $region,
        ]);

        $sns->publish([
            'PhoneNumber' => $to,
            'Message' => $body,
        ]);
    }
}
