<?php

namespace yellowrobot\courier\tests\unit;

use PHPUnit\Framework\TestCase;
use yellowrobot\courier\models\ChannelConfig;

class ChannelConfigTest extends TestCase
{
    private function validConfig(): ChannelConfig
    {
        $config = new ChannelConfig();
        $config->name = 'Craft Email';
        $config->handle = 'craftEmail';
        $config->type = 'email';

        return $config;
    }

    // ─── Validation ──────────────────────────────────────────

    public function testValidConfigPasses(): void
    {
        $this->assertTrue($this->validConfig()->validate());
    }

    public function testNameIsRequired(): void
    {
        $config = $this->validConfig();
        $config->name = '';

        $this->assertFalse($config->validate());
        $this->assertArrayHasKey('name', $config->getErrors());
    }

    public function testHandleIsRequired(): void
    {
        $config = $this->validConfig();
        $config->handle = '';

        $this->assertFalse($config->validate());
        $this->assertArrayHasKey('handle', $config->getErrors());
    }

    public function testTypeIsRequired(): void
    {
        $config = $this->validConfig();
        $config->type = '';

        $this->assertFalse($config->validate());
        $this->assertArrayHasKey('type', $config->getErrors());
    }

    public function testHandleMustStartWithLetter(): void
    {
        $config = $this->validConfig();
        $config->handle = '1stChannel';

        $this->assertFalse($config->validate());
    }

    public function testHandleRejectsHyphens(): void
    {
        $config = $this->validConfig();
        $config->handle = 'craft-email';

        $this->assertFalse($config->validate());
    }

    public function testHandleAllowsUnderscoresAndDigits(): void
    {
        $config = $this->validConfig();
        $config->handle = 'slack_alerts_2';

        $this->assertTrue($config->validate());
    }

    public function testEnabledDefaultsToTrue(): void
    {
        $this->assertTrue((new ChannelConfig())->enabled);
    }

    // ─── getSetting ──────────────────────────────────────────

    public function testGetSettingReturnsValue(): void
    {
        $config = $this->validConfig();
        $config->settings = ['webhookUrl' => 'https://example.test/hook'];

        $this->assertSame('https://example.test/hook', $config->getSetting('webhookUrl'));
    }

    public function testGetSettingReturnsDefaultWhenMissing(): void
    {
        $config = $this->validConfig();

        $this->assertSame('fallback', $config->getSetting('missing', 'fallback'));
        $this->assertNull($config->getSetting('missing'));
    }

    public function testGetSettingParsesEnvVars(): void
    {
        putenv('COURIER_TEST_SECRET=resolved-value');

        try {
            $config = $this->validConfig();
            $config->settings = ['token' => '$COURIER_TEST_SECRET'];

            $this->assertSame('resolved-value', $config->getSetting('token'));
        } finally {
            putenv('COURIER_TEST_SECRET');
        }
    }

    public function testGetSettingLeavesNonStringValuesAlone(): void
    {
        $config = $this->validConfig();
        $config->settings = ['port' => 587, 'tls' => true];

        $this->assertSame(587, $config->getSetting('port'));
        $this->assertTrue($config->getSetting('tls'));
    }
}
