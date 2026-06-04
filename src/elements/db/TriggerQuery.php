<?php

namespace yellowrobot\courier\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

class TriggerQuery extends ElementQuery
{
    public ?string $handle = null;
    public ?string $eventTrigger = null;

    public function handle(?string $value): static
    {
        $this->handle = $value;
        return $this;
    }

    public function eventTrigger(?string $value): static
    {
        $this->eventTrigger = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('courier_triggers');

        $this->query->select([
            'courier_triggers.handle',
            'courier_triggers.triggerMode',
            'courier_triggers.eventTrigger',
            'courier_triggers.rawEventClass',
            'courier_triggers.rawEventName',
            'courier_triggers.dateElementType',
            'courier_triggers.dateField',
            'courier_triggers.dateOffsetDays',
            'courier_triggers.dateSendTime',
            'courier_triggers.fixedDate',
            'courier_triggers.dateAudience',
            'courier_triggers.condition',
            'courier_triggers.recipients',
            'courier_triggers.cc',
            'courier_triggers.bcc',
            'courier_triggers.variables',
            'courier_triggers.channelIds',
            'courier_triggers.sendMode',
            'courier_triggers.templateId',
            // Pull the linked template's content onto the trigger for inline editing
            'subject' => 'courier_courier_tpl.subject',
            'htmlBody' => 'courier_courier_tpl.htmlBody',
            'textBody' => 'courier_courier_tpl.textBody',
        ]);

        $this->query->leftJoin(
            '{{%courier_templates}} courier_courier_tpl',
            '[[courier_courier_tpl.id]] = [[courier_triggers.templateId]]',
        );

        // Index aggregates: sendCount + lastFired, via a grouped subquery on the
        // logs table keyed by triggerUid = the trigger element's uid. Avoids the
        // per-row count()/order() the element used to run in attributeHtml.
        $logAgg = (new \craft\db\Query())
            ->select([
                'triggerUid',
                'sendCount' => 'COUNT(*)',
                'lastFired' => 'MAX([[dateSent]])',
            ])
            ->from('{{%courier_logs}}')
            ->where(['isTest' => false])
            ->groupBy(['triggerUid']);

        $this->query->addSelect([
            'sendCount' => 'courier_courier_logs.sendCount',
            'lastFired' => 'courier_courier_logs.lastFired',
        ]);

        $this->query->leftJoin(
            ['courier_courier_logs' => $logAgg],
            '[[courier_courier_logs.triggerUid]] = [[elements.uid]]',
        );

        if ($this->handle !== null) {
            $this->subQuery->andWhere(Db::parseParam('courier_triggers.handle', $this->handle));
        }

        if ($this->eventTrigger !== null) {
            $this->subQuery->andWhere(Db::parseParam('courier_triggers.eventTrigger', $this->eventTrigger));
        }

        return parent::beforePrepare();
    }
}
