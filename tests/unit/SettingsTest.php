<?php

namespace yellowrobot\courier\tests\unit;

use PHPUnit\Framework\TestCase;
use yellowrobot\courier\models\Settings;

class SettingsTest extends TestCase
{
    // ─── Defaults ────────────────────────────────────────────

    public function testDefaultsAreNull(): void
    {
        $settings = new Settings();

        $this->assertNull($settings->defaultLayout);
        $this->assertNull($settings->logRetentionDays);
    }

    // ─── defaultLayout ───────────────────────────────────────

    public function testDefaultLayoutAcceptsString(): void
    {
        $settings = new Settings();
        $settings->defaultLayout = '_layouts/email';

        $this->assertTrue($settings->validate(['defaultLayout']));
    }

    public function testNullDefaultLayoutPasses(): void
    {
        $settings = new Settings();
        $settings->defaultLayout = null;

        $this->assertTrue($settings->validate(['defaultLayout']));
    }

    public function testDefaultLayoutIsEnvParsed(): void
    {
        $behaviors = (new Settings())->behaviors();

        $this->assertArrayHasKey('parser', $behaviors);
        $this->assertContains('defaultLayout', $behaviors['parser']['attributes']);
    }

    // ─── logRetentionDays ────────────────────────────────────

    public function testLogRetentionAcceptsPositiveInteger(): void
    {
        $settings = new Settings();
        $settings->logRetentionDays = 30;

        $this->assertTrue($settings->validate(['logRetentionDays']));
    }

    public function testLogRetentionAcceptsZero(): void
    {
        $settings = new Settings();
        $settings->logRetentionDays = 0;

        $this->assertTrue($settings->validate(['logRetentionDays']));
    }

    public function testLogRetentionRejectsNegative(): void
    {
        $settings = new Settings();
        $settings->logRetentionDays = -5;

        $this->assertFalse($settings->validate(['logRetentionDays']));
    }

    public function testNullLogRetentionPasses(): void
    {
        $settings = new Settings();
        $settings->logRetentionDays = null;

        $this->assertTrue($settings->validate(['logRetentionDays']));
    }
}
