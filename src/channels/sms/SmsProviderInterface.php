<?php

namespace yellowrobot\courier\channels\sms;

interface SmsProviderInterface
{
    public function send(string $to, string $body): void;
}
