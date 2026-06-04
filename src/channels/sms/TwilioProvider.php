<?php

namespace yellowrobot\courier\channels\sms;

use Craft;
use craft\helpers\App;

class TwilioProvider implements SmsProviderInterface
{
    public function send(string $to, string $body): void
    {
        $sid = App::env('TWILIO_SID');
        $token = App::env('TWILIO_TOKEN');
        $from = App::env('TWILIO_FROM');

        if (!$sid || !$token || !$from) {
            throw new \RuntimeException('Twilio credentials not configured. Set TWILIO_SID, TWILIO_TOKEN, and TWILIO_FROM environment variables.');
        }

        $client = Craft::createGuzzleClient();
        $response = $client->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
            'auth' => [$sid, $token],
            'form_params' => [
                'To' => $to,
                'From' => $from,
                'Body' => $body,
            ],
            'timeout' => 10,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Twilio API returned status {$status}");
        }
    }
}
