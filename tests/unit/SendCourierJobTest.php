<?php

namespace yellowrobot\courier\tests\unit;

use PHPUnit\Framework\TestCase;
use yellowrobot\courier\jobs\SendCourierJob;

class SendCourierJobTest extends TestCase
{
    public function testDefaults(): void
    {
        $job = new SendCourierJob();

        $this->assertNull($job->triggerUid);
        $this->assertSame('', $job->templateHandle);
        $this->assertSame('', $job->channelUid);
        $this->assertFalse($job->isTest);
        $this->assertSame([], $job->variables);
        $this->assertNull($job->recipients);
        $this->assertNull($job->cc);
        $this->assertNull($job->bcc);
        $this->assertSame('list', $job->sendMode);
    }

    public function testConfigAssignsProperties(): void
    {
        $job = new SendCourierJob([
            'triggerUid' => 'abc-123',
            'templateHandle' => 'welcomeEmail',
            'channelUid' => 'def-456',
            'recipients' => '{{ user.email }}',
            'sendMode' => 'individual',
            'isTest' => true,
        ]);

        $this->assertSame('abc-123', $job->triggerUid);
        $this->assertSame('welcomeEmail', $job->templateHandle);
        $this->assertSame('def-456', $job->channelUid);
        $this->assertSame('{{ user.email }}', $job->recipients);
        $this->assertSame('individual', $job->sendMode);
        $this->assertTrue($job->isTest);
    }

    public function testDescriptionIncludesTemplateHandle(): void
    {
        $job = new SendCourierJob(['templateHandle' => 'welcomeEmail']);

        $this->assertStringContainsString('welcomeEmail', (string) $job->getDescription());
    }
}
