<?php

namespace yellowrobot\courier\tests\unit;

use PHPUnit\Framework\TestCase;
use yellowrobot\courier\channels\ChannelTypeInterface;
use yellowrobot\courier\channels\types\DiscordChannelType;
use yellowrobot\courier\channels\types\EmailChannelType;
use yellowrobot\courier\channels\types\SlackChannelType;
use yellowrobot\courier\channels\types\SmsChannelType;
use yellowrobot\courier\channels\types\SmtpEmailChannelType;
use yellowrobot\courier\channels\types\WebhookChannelType;

/**
 * The capability flags drive real behavior — hasSubject() controls whether the
 * Subject field applies, supportsHtml() controls which body the channel sends
 * and what the preview defaults to — so the matrix is pinned here.
 */
class ChannelTypesTest extends TestCase
{
    /** @var array<class-string<ChannelTypeInterface>> */
    private const TYPES = [
        EmailChannelType::class,
        SmtpEmailChannelType::class,
        SlackChannelType::class,
        DiscordChannelType::class,
        WebhookChannelType::class,
        SmsChannelType::class,
    ];

    public function testHandlesAreUnique(): void
    {
        $handles = array_map(fn(string $class) => $class::handle(), self::TYPES);

        $this->assertSame($handles, array_unique($handles), 'Channel type handles must be unique');
    }

    public function testExpectedHandles(): void
    {
        $expected = [
            EmailChannelType::class => 'email',
            SmtpEmailChannelType::class => 'smtp',
            SlackChannelType::class => 'slack',
            DiscordChannelType::class => 'discord',
            WebhookChannelType::class => 'webhook',
            SmsChannelType::class => 'sms',
        ];

        foreach ($expected as $class => $handle) {
            $this->assertSame($handle, $class::handle());
        }
    }

    public function testGetHandleMatchesStaticHandle(): void
    {
        foreach (self::TYPES as $class) {
            $type = new $class();
            $this->assertSame($class::handle(), $type->getHandle());
        }
    }

    public function testAllTypesAreSelectable(): void
    {
        foreach (self::TYPES as $class) {
            $this->assertTrue($class::isSelectable(), "{$class} should be selectable");
        }
    }

    public function testSubjectCapabilityMatrix(): void
    {
        $expected = [
            EmailChannelType::class => true,
            SmtpEmailChannelType::class => true,
            SlackChannelType::class => false,
            DiscordChannelType::class => false,
            WebhookChannelType::class => false,
            SmsChannelType::class => false,
        ];

        foreach ($expected as $class => $hasSubject) {
            $this->assertSame($hasSubject, (new $class())->hasSubject(), "{$class} hasSubject()");
        }
    }

    public function testHtmlCapabilityMatrix(): void
    {
        $expected = [
            EmailChannelType::class => true,
            SmtpEmailChannelType::class => true,
            // Webhook forwards the HTML body in its payload
            WebhookChannelType::class => true,
            SlackChannelType::class => false,
            DiscordChannelType::class => false,
            SmsChannelType::class => false,
        ];

        foreach ($expected as $class => $supportsHtml) {
            $this->assertSame($supportsHtml, (new $class())->supportsHtml(), "{$class} supportsHtml()");
        }
    }

    public function testDisplayNamesAreNonEmpty(): void
    {
        foreach (self::TYPES as $class) {
            $this->assertNotSame('', $class::displayName());
            $this->assertSame($class::displayName(), (new $class())->getName());
        }
    }

    public function testValidateSettingsReturnsNoErrorsForEmptySettingsByDefault(): void
    {
        // Types may require settings (e.g. webhook URL) — this only pins the
        // base-class contract that the return shape is an error array.
        foreach (self::TYPES as $class) {
            $this->assertIsArray((new $class())->validateSettings([]));
        }
    }
}
