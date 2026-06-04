<?php

namespace yellowrobot\courier\tests\integration;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Install migration's schema by inspecting its source — catches
 * dropped columns, lost indexes, and broken teardown ordering without a DB.
 */
class MigrationSchemaTest extends TestCase
{
    private string $migrationSource;

    protected function setUp(): void
    {
        $this->migrationSource = file_get_contents(
            dirname(__DIR__, 2) . '/src/migrations/Install.php'
        );
    }

    // ─── courier_templates ───────────────────────────────────

    public function testTemplatesTableHasRequiredColumns(): void
    {
        $section = $this->extractTableSection('courier_templates');

        foreach (['id', 'handle', 'subject', 'htmlBody', 'textBody', 'bodyFile', 'dateCreated', 'dateUpdated'] as $column) {
            $this->assertStringContainsString("'{$column}'", $section, "Templates table missing column: {$column}");
        }
    }

    public function testTemplatesTableIsElementExtension(): void
    {
        // Element-extension table: shares its PK with the elements row (no own uid),
        // and cascade-deletes with the element.
        $section = $this->extractTableSection('courier_templates');
        $this->assertStringContainsString('PRIMARY KEY([[id]])', $section);
        $this->assertStringNotContainsString("'uid'", $section, 'Element-extension tables must not define their own uid');

        $this->assertMatchesRegularExpression(
            '/addForeignKey\(\s*null,\s*\'{{%courier_templates}}\',\s*\'id\',\s*\'{{%elements}}\',\s*\'id\',\s*\'CASCADE\'/s',
            $this->migrationSource,
            'Templates table must cascade-delete with its element row'
        );
    }

    public function testTemplatesTableHasHandleIndex(): void
    {
        // Deliberately non-unique: handle uniqueness is app-level so Db::upsert
        // (keyed on id) can never collide on the index.
        $this->assertMatchesRegularExpression(
            '/createIndex\(null,\s*\'{{%courier_templates}}\',\s*\[\'handle\'\]\);/',
            $this->migrationSource,
            'Templates table should have a (non-unique) index on handle'
        );
    }

    // ─── courier_triggers ────────────────────────────────────

    public function testTriggersTableHasRequiredColumns(): void
    {
        $section = $this->extractTableSection('courier_triggers');

        $required = [
            'id', 'handle', 'eventTrigger', 'rawEventClass', 'rawEventName',
            'condition', 'recipients', 'cc', 'bcc', 'variables', 'channelIds',
            'sendMode', 'templateId', 'dateCreated', 'dateUpdated',
        ];
        foreach ($required as $column) {
            $this->assertStringContainsString("'{$column}'", $section, "Triggers table missing column: {$column}");
        }
    }

    public function testTriggersTemplateLinkIsUniqueAndNullsOnDelete(): void
    {
        // 1:1 trigger↔template: unique index on templateId…
        $this->assertMatchesRegularExpression(
            '/createIndex\(null,\s*\'{{%courier_triggers}}\',\s*\[\'templateId\'\],\s*true\);/',
            $this->migrationSource,
            'templateId must have a unique index (1:1 trigger↔template)'
        );

        // …and SET NULL when the linked template element is removed.
        $this->assertMatchesRegularExpression(
            '/addForeignKey\(\s*null,\s*\'{{%courier_triggers}}\',\s*\'templateId\',\s*\'{{%elements}}\',\s*\'id\',\s*\'SET NULL\'/s',
            $this->migrationSource,
            'templateId FK must SET NULL on template deletion'
        );
    }

    public function testTriggersTableHasEventTriggerIndex(): void
    {
        $this->assertMatchesRegularExpression(
            '/createIndex\(null,\s*\'{{%courier_triggers}}\',\s*\[\'eventTrigger\'\]\);/',
            $this->migrationSource
        );
    }

    // ─── courier_channels ────────────────────────────────────

    public function testChannelsTableHasRequiredColumns(): void
    {
        $section = $this->extractTableSection('courier_channels');

        foreach (['id', 'name', 'handle', 'type', 'settings', 'enabled', 'sortOrder', 'dateCreated', 'dateUpdated', 'uid'] as $column) {
            $this->assertStringContainsString("'{$column}'", $section, "Channels table missing column: {$column}");
        }
    }

    public function testChannelsHandleIsUnique(): void
    {
        $this->assertMatchesRegularExpression(
            '/createIndex\(null,\s*\'{{%courier_channels}}\',\s*\[\'handle\'\],\s*true\);/',
            $this->migrationSource,
            'Channel handles must be unique'
        );
    }

    public function testDefaultEmailChannelIsSeeded(): void
    {
        // Email must work out of the box — the install seeds a Craft mailer channel.
        $this->assertMatchesRegularExpression(
            '/insert\(\'{{%courier_channels}}\',\s*\[.*?\'craftEmail\'.*?\]/s',
            $this->migrationSource,
            'Install must seed the default Craft Email channel'
        );
        $this->assertStringContainsString("'type' => 'email'", $this->migrationSource);
    }

    // ─── courier_logs ────────────────────────────────────────

    public function testLogsTableHasRequiredColumns(): void
    {
        $section = $this->extractTableSection('courier_logs');

        $required = [
            'id', 'triggerUid', 'templateHandle', 'channel', 'recipient', 'subject',
            'status', 'errorMessage', 'isTest', 'elementId', 'elementType',
            'dateSent', 'dateCreated', 'dateUpdated', 'uid',
        ];
        foreach ($required as $column) {
            $this->assertStringContainsString("'{$column}'", $section, "Logs table missing column: {$column}");
        }
    }

    public function testLogsTableHasQueryIndexes(): void
    {
        foreach (['triggerUid', 'templateHandle', 'dateSent', 'status', 'isTest'] as $column) {
            $this->assertMatchesRegularExpression(
                '/createIndex\(null,\s*\'{{%courier_logs}}\',\s*\[\'' . $column . '\'\]\);/',
                $this->migrationSource,
                "Logs table missing index on {$column}"
            );
        }
    }

    public function testLogsRecipientColumnIsText(): void
    {
        // recipient must be text() (not string(255)) to hold list-mode sends
        $section = $this->extractTableSection('courier_logs');
        $this->assertMatchesRegularExpression('/\'recipient\' => \$this->text\(\)/', $section);
    }

    // ─── courier_scheduled ───────────────────────────────────

    public function testScheduledTableHasRequiredColumns(): void
    {
        $section = $this->extractTableSection('courier_scheduled');

        foreach (['id', 'triggerId', 'elementId', 'resolvedDate', 'sendAt', 'dateCreated', 'dateUpdated', 'uid'] as $column) {
            $this->assertStringContainsString("'{$column}'", $section, "Scheduled table missing column: {$column}");
        }
    }

    public function testScheduledTableHasIdempotencyKey(): void
    {
        // One pending send per trigger × element × resolved date — resaves with
        // the same date can't refire; a moved date gets its own row.
        $this->assertMatchesRegularExpression(
            '/createIndex\(null,\s*\'{{%courier_scheduled}}\',\s*\[\'triggerId\',\s*\'elementId\',\s*\'resolvedDate\'\],\s*true\);/',
            $this->migrationSource,
            'Scheduled table needs the unique (triggerId, elementId, resolvedDate) key'
        );
    }

    public function testScheduledTableCascadesWithTrigger(): void
    {
        $this->assertMatchesRegularExpression(
            '/addForeignKey\(\s*null,\s*\'{{%courier_scheduled}}\',\s*\'triggerId\',\s*\'{{%courier_triggers}}\',\s*\'id\',\s*\'CASCADE\'/s',
            $this->migrationSource,
            'Scheduled rows must cascade-delete with their trigger'
        );
    }

    public function testTriggersTableHasDateModeColumns(): void
    {
        $section = $this->extractTableSection('courier_triggers');

        foreach (['triggerMode', 'dateElementType', 'dateField', 'dateOffsetDays', 'dateSendTime', 'fixedDate', 'dateAudience'] as $column) {
            $this->assertStringContainsString("'{$column}'", $section, "Triggers table missing column: {$column}");
        }
    }

    // ─── safeDown ────────────────────────────────────────────

    public function testSafeDownDropsAllTables(): void
    {
        $safeDown = $this->extractSafeDown();

        foreach (['courier_logs', 'courier_scheduled', 'courier_triggers', 'courier_channels', 'courier_templates'] as $table) {
            $this->assertStringContainsString(
                "dropTableIfExists('{{%{$table}}}')",
                $safeDown,
                "safeDown must drop {$table}"
            );
        }
    }

    public function testSafeDownCleansOrphanedElementRows(): void
    {
        // Element-extension rows cascade with the table, but the shared
        // {{%elements}} rows would orphan — safeDown must clear them.
        $safeDown = $this->extractSafeDown();
        $this->assertStringContainsString('Db::delete', $safeDown);
        $this->assertStringContainsString('Table::ELEMENTS', $safeDown);
    }

    public function testSafeDownDropsDependentTablesFirst(): void
    {
        // Among the drop calls, logs (references trigger uids) must go before
        // triggers, and triggers (FK to template elements) before templates.
        $safeDown = $this->extractSafeDown();

        $dropPos = fn(string $table) => strpos($safeDown, "dropTableIfExists('{{%{$table}}}')");
        $this->assertLessThan($dropPos('courier_triggers'), $dropPos('courier_logs'));
        $this->assertLessThan($dropPos('courier_triggers'), $dropPos('courier_scheduled'), 'scheduled (FK to triggers) must drop first');
        $this->assertLessThan($dropPos('courier_templates'), $dropPos('courier_triggers'));
    }

    // ─── Idempotency ─────────────────────────────────────────

    public function testEveryTableHasIndependentExistenceCheck(): void
    {
        $count = substr_count($this->migrationSource, 'tableExists');
        $this->assertGreaterThanOrEqual(4, $count, 'All four tables need independent existence checks');
    }

    // ─── Helpers ─────────────────────────────────────────────

    private function extractTableSection(string $tableName): string
    {
        $pattern = "/createTable\(\s*'\{\{%{$tableName}}}'\s*,\s*\[(.*?)\]\s*\);/s";
        $this->assertSame(1, preg_match($pattern, $this->migrationSource, $matches), "createTable for {$tableName} not found");
        return $matches[1];
    }

    private function extractSafeDown(): string
    {
        $pos = strpos($this->migrationSource, 'function safeDown');
        $this->assertNotFalse($pos, 'safeDown must exist');
        return substr($this->migrationSource, $pos);
    }
}
