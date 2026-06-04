<?php

namespace yellowrobot\courier\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;

class Install extends Migration
{
    public function safeUp(): bool
    {
        // Templates — element-backed content record (carry-forward + bodyFile override)
        if (!$this->db->tableExists('{{%courier_templates}}')) {
            $this->createTable('{{%courier_templates}}', [
                // Element-extension table: shares its PK/uid with the elements row.
                'id' => $this->integer()->notNull(),
                'handle' => $this->string(255)->notNull(),
                'subject' => $this->string(255)->notNull(),
                'htmlBody' => $this->text()->notNull(),
                'textBody' => $this->text()->null(),
                'bodyFile' => $this->string(255)->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'PRIMARY KEY([[id]])',
            ]);

            $this->addForeignKey(
                null,
                '{{%courier_templates}}',
                'id',
                '{{%elements}}',
                'id',
                'CASCADE',
                null,
            );

            // Non-unique for the same reason as triggers (avoids the upsert/handle collision)
            $this->createIndex(null, '{{%courier_templates}}', ['handle']);
        }

        // Triggers — element-backed wiring record (the hook definition, now DB-managed)
        if (!$this->db->tableExists('{{%courier_triggers}}')) {
            $this->createTable('{{%courier_triggers}}', [
                // Element-extension table: shares its PK/uid with the elements row.
                'id' => $this->integer()->notNull(),
                'handle' => $this->string(255)->notNull(),
                // 'event' triggers listen for a registry/raw event; 'date' triggers
                // fire off a date field via the scheduler scan.
                'triggerMode' => $this->string(10)->notNull()->defaultValue('event'),
                'eventTrigger' => $this->string(255)->null(),
                'rawEventClass' => $this->string(255)->null(),
                'rawEventName' => $this->string(255)->null(),
                'dateElementType' => $this->string(255)->null(),
                'dateField' => $this->string(255)->null(),
                'dateOffsetDays' => $this->integer()->notNull()->defaultValue(0),
                'dateSendTime' => $this->string(5)->null(),
                // Alternative to dateField: one shared date for every matching
                // element ("remind all registrants 7 days before June 14")
                'fixedDate' => $this->date()->null(),
                // Fixed-date triggers only: 'elements' = one send per matching
                // element; 'once' = a single send to the recipients list.
                'dateAudience' => $this->string(10)->notNull()->defaultValue('elements'),
                'condition' => $this->text()->null(),
                'recipients' => $this->text()->null(),
                'cc' => $this->text()->null(),
                'bcc' => $this->text()->null(),
                'variables' => $this->string(255)->null(),
                'channelIds' => $this->text()->notNull(),
                'sendMode' => $this->string(20)->notNull()->defaultValue('list'),
                'templateId' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'PRIMARY KEY([[id]])',
            ]);

            // Element FK — cascade delete with the element row
            $this->addForeignKey(
                null,
                '{{%courier_triggers}}',
                'id',
                '{{%elements}}',
                'id',
                'CASCADE',
                null,
            );

            // 1:1 template link — null out if the linked template element is removed
            $this->addForeignKey(
                null,
                '{{%courier_triggers}}',
                'templateId',
                '{{%elements}}',
                'id',
                'SET NULL',
                null,
            );

            // Non-unique: handle uniqueness is enforced in app validation, not the DB,
            // so Db::upsert (keyed on id) can't ever collide on the handle index.
            $this->createIndex(null, '{{%courier_triggers}}', ['handle']);
            $this->createIndex(null, '{{%courier_triggers}}', ['templateId'], true);
            $this->createIndex(null, '{{%courier_triggers}}', ['eventTrigger']);
        }

        // Channels — named, reusable channel configs (instances of code-defined ChannelTypes)
        if (!$this->db->tableExists('{{%courier_channels}}')) {
            $this->createTable('{{%courier_channels}}', [
                'id' => $this->primaryKey(),
                'name' => $this->string(255)->notNull(),
                'handle' => $this->string(255)->notNull(),
                'type' => $this->string(64)->notNull(),
                'settings' => $this->text()->null(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'sortOrder' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%courier_channels}}', ['handle'], true);
            $this->createIndex(null, '{{%courier_channels}}', ['type']);

            // Seed a default Craft mailer email channel so email works out of the box
            $now = Db::prepareDateForDb(new \DateTime());
            $this->insert('{{%courier_channels}}', [
                'name' => 'Craft Email',
                'handle' => 'craftEmail',
                'type' => 'email',
                'settings' => null,
                'enabled' => true,
                'sortOrder' => 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }

        // Scheduled sends — pending time-shifted/date-based sends, promoted to
        // immediate jobs by the scheduler once due (after a send-time re-check)
        if (!$this->db->tableExists('{{%courier_scheduled}}')) {
            $this->createTable('{{%courier_scheduled}}', [
                'id' => $this->primaryKey(),
                'triggerId' => $this->integer()->notNull(),
                // 0 for once-audience sends (no element; keeps the unique key
                // honest — NULLs wouldn't dedupe in MySQL unique indexes)
                'elementId' => $this->integer()->notNull(),
                'resolvedDate' => $this->date()->notNull(),
                'sendAt' => $this->dateTime()->notNull(),
                // Set when promoted (sent or skipped); the row then persists as the
                // once-per-element-per-date marker instead of being deleted.
                'processedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Idempotency key: one pending send per trigger+element+date. A moved
            // date gets its own row; resaves with the same date don't refire.
            $this->createIndex(null, '{{%courier_scheduled}}', ['triggerId', 'elementId', 'resolvedDate'], true);
            $this->createIndex(null, '{{%courier_scheduled}}', ['sendAt']);

            $this->addForeignKey(
                null,
                '{{%courier_scheduled}}',
                'triggerId',
                '{{%courier_triggers}}',
                'id',
                'CASCADE',
                null,
            );
        }

        // Logs — send audit (carry-forward + triggerUid, channel, isTest)
        if (!$this->db->tableExists('{{%courier_logs}}')) {
            $this->createTable('{{%courier_logs}}', [
                'id' => $this->primaryKey(),
                'triggerUid' => $this->string(36)->null(),
                'templateHandle' => $this->string(255)->notNull(),
                'channel' => $this->string(64)->null(),
                'recipient' => $this->text()->notNull(),
                'subject' => $this->string(255)->notNull(),
                'status' => $this->string(20)->notNull()->defaultValue('queued'),
                'errorMessage' => $this->text()->null(),
                'isTest' => $this->boolean()->notNull()->defaultValue(false),
                'elementId' => $this->integer()->null(),
                'elementType' => $this->string(255)->null(),
                'dateSent' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, '{{%courier_logs}}', ['triggerUid']);
            $this->createIndex(null, '{{%courier_logs}}', ['templateHandle']);
            $this->createIndex(null, '{{%courier_logs}}', ['dateSent']);
            $this->createIndex(null, '{{%courier_logs}}', ['status']);
            $this->createIndex(null, '{{%courier_logs}}', ['isTest']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Element-extension tables cascade-delete their own rows when dropped, but
        // the shared {{%elements}} rows would be orphaned. Clear them first — guarded
        // so a partial install (one element table missing) doesn't fatal here.
        $elementTypes = [];
        if ($this->db->tableExists('{{%courier_triggers}}')) {
            $elementTypes[] = \yellowrobot\courier\elements\Trigger::class;
        }
        if ($this->db->tableExists('{{%courier_templates}}')) {
            $elementTypes[] = \yellowrobot\courier\elements\EmailTemplate::class;
        }
        if (!empty($elementTypes) && $this->db->tableExists(\craft\db\Table::ELEMENTS)) {
            \craft\helpers\Db::delete(\craft\db\Table::ELEMENTS, ['type' => $elementTypes]);
        }

        $this->dropTableIfExists('{{%courier_logs}}');
        $this->dropTableIfExists('{{%courier_scheduled}}');
        $this->dropTableIfExists('{{%courier_triggers}}');
        $this->dropTableIfExists('{{%courier_channels}}');
        $this->dropTableIfExists('{{%courier_templates}}');

        return true;
    }
}
