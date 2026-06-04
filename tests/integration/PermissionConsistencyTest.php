<?php

namespace yellowrobot\courier\tests\integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the permission model by inspecting source: every CP entry point must be
 * gated, and the manage/edit-templates boundary on elements must hold —
 * content editors may edit message copy, but only managers touch wiring.
 */
class PermissionConsistencyTest extends TestCase
{
    private function source(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/src/' . $path;
        $this->assertFileExists($file);
        return file_get_contents($file);
    }

    // ─── Controller gates ────────────────────────────────────

    /** @return array<string,array{string}> */
    public static function controllers(): array
    {
        return [
            'triggers' => ['controllers/TriggersController.php'],
            'channels' => ['controllers/ChannelsController.php'],
            'logs' => ['controllers/LogsController.php'],
        ];
    }

    #[DataProvider('controllers')]
    public function testControllerGatesEveryRequest(string $path): void
    {
        $source = $this->source($path);

        $this->assertStringContainsString('function beforeAction', $source, "{$path} must define beforeAction");
        $this->assertStringContainsString('requireCpRequest()', $source, "{$path} must require a CP request");
        $this->assertStringContainsString("requirePermission('courier:manage')", $source, "{$path} must require courier:manage");
    }

    // ─── Element permission boundary ─────────────────────────

    public function testTriggerWiringIsManageOnly(): void
    {
        $source = $this->source('elements/Trigger.php');

        foreach (['canView', 'canSave', 'canDelete', 'canDuplicate'] as $method) {
            $body = $this->extractMethodBody($source, $method);
            $this->assertStringContainsString('courier:manage', $body, "Trigger::{$method} must check courier:manage");
            $this->assertStringNotContainsString(
                'courier:edit-templates',
                $body,
                "Trigger::{$method} must NOT accept edit-templates — wiring is manager-only"
            );
        }
    }

    public function testTemplateContentIsEditableByEditors(): void
    {
        $source = $this->source('elements/EmailTemplate.php');

        // Editors may view and edit message copy…
        foreach (['canView', 'canSave'] as $method) {
            $body = $this->extractMethodBody($source, $method);
            $this->assertStringContainsString('courier:edit-templates', $body, "EmailTemplate::{$method} must allow edit-templates");
            $this->assertStringContainsString('courier:manage', $body, "EmailTemplate::{$method} must allow courier:manage");
        }

        // …but lifecycle actions stay manager-only.
        foreach (['canDelete', 'canDuplicate'] as $method) {
            $body = $this->extractMethodBody($source, $method);
            $this->assertStringContainsString('courier:manage', $body, "EmailTemplate::{$method} must check courier:manage");
            $this->assertStringNotContainsString(
                'courier:edit-templates',
                $body,
                "EmailTemplate::{$method} must NOT accept edit-templates"
            );
        }
    }

    // ─── Plugin registration ─────────────────────────────────

    public function testPluginRegistersBothPermissions(): void
    {
        $source = $this->source('Courier.php');

        $this->assertStringContainsString("'courier:manage'", $source);
        $this->assertStringContainsString("'courier:edit-templates'", $source);
        $this->assertStringContainsString('EVENT_REGISTER_PERMISSIONS', $source);
    }

    public function testCpWiringIsGuardedToCpRequests(): void
    {
        // Routes + permission registration only matter on CP requests; front-end
        // boot must stay lean.
        $source = $this->source('Courier.php');
        $this->assertStringContainsString('getIsCpRequest()', $source);
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function extractMethodBody(string $source, string $methodName): string
    {
        $pattern = '/function\s+' . preg_quote($methodName) . '\s*\([^)]*\)[^{]*\{(.*?)\n\s{4}\}/s';
        $this->assertSame(1, preg_match($pattern, $source, $matches), "Method {$methodName} not found");
        return $matches[1];
    }
}
