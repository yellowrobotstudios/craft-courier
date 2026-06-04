<?php

namespace yellowrobot\courier\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Date;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\Trigger;
use yellowrobot\courier\records\ScheduledSendRecord;

/**
 * Time-shifted sending. Two halves:
 *
 *  - scan():    find elements whose [date field ± offset] lands within the
 *               lookahead window and insert pending rows (idempotent — keyed
 *               on trigger × element × resolved date).
 *  - promote(): once a row's sendAt is due, RE-CHECK against current state
 *               (element exists, trigger enabled, date unchanged, conditions
 *               still match) and queue the normal send jobs. Staleness loses.
 *
 * "N days before" means "N days or less": an element that enters the window
 * late (created 1 day before its date with a 3-days-before trigger) is
 * scheduled to send immediately rather than skipped. Elements whose date has
 * already passed never fire.
 */
class Scheduler extends Component
{
    public const HEARTBEAT_CACHE_KEY = 'courier:scheduler:heartbeat';

    /** How far ahead scan() schedules (covers missed crons). */
    public const HORIZON_HOURS = 48;
    /** How long past sendAt promote() will still send (server-outage grace). */
    public const MAX_STALENESS_HOURS = 24;

    /**
     * Compute when a send should go out for a given date value: the date ±
     * offset days, at the send time (site timezone; default 09:00).
     */
    public static function computeSendAt(\DateTimeInterface $dateValue, int $offsetDays, ?string $sendTime): \DateTime
    {
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());

        $sendAt = \DateTime::createFromInterface($dateValue)->setTimezone($tz);
        $sendAt->modify(sprintf('%+d days', $offsetDays));

        [$hour, $minute] = array_map('intval', explode(':', $sendTime ?: '09:00'));
        $sendAt->setTime($hour, $minute);

        return $sendAt;
    }

    /**
     * Scan all enabled date triggers and insert pending rows for elements whose
     * send moment falls inside the window. Idempotent: safe to run any number
     * of times at any cadence.
     *
     * @return int rows inserted
     */
    public function scan(): int
    {
        $now = DateTimeHelper::now();
        $inserted = 0;

        $triggers = Trigger::find()->status('enabled')->all();
        foreach ($triggers as $trigger) {
            /** @var Trigger $trigger */
            if (!$trigger->isDateMode() || (!$trigger->dateField && !$trigger->fixedDate)) {
                continue;
            }

            // Once-audience: a single send keyed to the trigger itself
            // (elementId 0) — nothing to iterate, no conditions to match.
            if ($trigger->isOnceMode()) {
                $fixedValue = $this->fixedDateValue($trigger);
                if (!$fixedValue) {
                    continue;
                }
                $sendAt = $this->effectiveSendAt($trigger, $fixedValue, $now);
                if ($sendAt && $this->insertPending($trigger, 0, $fixedValue, $sendAt)) {
                    $inserted++;
                }
                continue;
            }

            $elementType = $trigger->getBoundElementType();
            if (!$elementType) {
                Craft::warning("Date trigger '{$trigger->handle}' has no valid element type; skipping scan.", __METHOD__);
                continue;
            }

            // Fixed-date triggers share one date across all matching elements —
            // gate the whole trigger by its window before querying anything.
            if ($trigger->fixedDate) {
                $fixedValue = $this->fixedDateValue($trigger);
                if (!$fixedValue || !$this->effectiveSendAt($trigger, $fixedValue, $now)) {
                    continue;
                }
            }

            foreach ($this->findCandidates($trigger, $elementType, $now) as $element) {
                $dateValue = $this->resolveTriggerDate($trigger, $element);
                if (!$dateValue) {
                    continue;
                }

                $sendAt = $this->effectiveSendAt($trigger, $dateValue, $now);
                if (!$sendAt) {
                    continue;
                }

                // Conditions are re-checked at promote time too, but filtering here
                // keeps non-matching elements out of the pending list entirely.
                if (!$trigger->matchesConditions($element)) {
                    continue;
                }

                if ($this->insertPending($trigger, $element->id, $dateValue, $sendAt)) {
                    $inserted++;
                }
            }
        }

        return $inserted;
    }

    /**
     * The effective send moment for a date value — computeSendAt() plus the
     * "N days or less" late-entry handling — or null when it falls outside the
     * scan window (too far out, or already past).
     */
    private function effectiveSendAt(Trigger $trigger, \DateTimeInterface $dateValue, \DateTime $now): ?\DateTime
    {
        $sendAt = self::computeSendAt($dateValue, $trigger->dateOffsetDays, $trigger->dateSendTime);

        // "N days before" = "N days or less": a late entry whose date is still
        // ahead sends now instead of being skipped.
        if ($trigger->dateOffsetDays < 0 && $sendAt < $now) {
            $dateStillAhead = \DateTime::createFromInterface($dateValue)->setTime(23, 59, 59) >= $now;
            if ($dateStillAhead) {
                $sendAt = clone $now;
            }
        }

        $horizon = (clone $now)->modify('+' . self::HORIZON_HOURS . ' hours');
        if ($sendAt > $horizon || $sendAt < (clone $now)->modify('-' . self::MAX_STALENESS_HOURS . ' hours')) {
            return null;
        }

        return $sendAt;
    }

    /**
     * Promote due rows: re-check against current state and queue the sends.
     *
     * @return array{sent:int,skipped:int}
     */
    public function promote(): array
    {
        $now = DateTimeHelper::now();
        $sent = 0;
        $skipped = 0;

        $due = ScheduledSendRecord::find()
            ->where(['<=', 'sendAt', Db::prepareDateForDb($now)])
            ->andWhere(['processedAt' => null])
            ->orderBy(['sendAt' => SORT_ASC])
            ->all();

        foreach ($due as $row) {
            /** @var ScheduledSendRecord $row */
            $reason = $this->recheck($row, $now);

            if ($reason === null) {
                $trigger = Trigger::find()->id($row->triggerId)->status(null)->one();
                // elementId 0 = once-audience: send with no element context.
                $isOnce = (int) $row->elementId === 0;
                $object = $isOnce ? null : $this->loadElement($trigger, $row->elementId);
                if ($trigger && ($isOnce || $object) && Courier::$plugin->hook->queueTriggerSends($trigger, $object)) {
                    $sent++;
                } else {
                    $skipped++;
                }
            } else {
                $trigger = Trigger::find()->id($row->triggerId)->status(null)->one();
                Courier::$plugin->log->logSend(
                    $trigger?->uid,
                    (string) ($trigger?->handle ?? "trigger#{$row->triggerId}"),
                    null,
                    '',
                    '',
                    'skipped',
                    $reason,
                    elementId: $row->elementId,
                );
                $skipped++;
            }

            // Keep the row as the once-per-element-per-date marker — the unique
            // key blocks the next scan from rescheduling this exact send.
            $row->processedAt = Db::prepareDateForDb($now);
            $row->save(false);
        }

        // Processed markers only matter while their date is near; prune old ones.
        Db::delete('{{%courier_scheduled}}', [
            'and',
            ['not', ['processedAt' => null]],
            ['<', 'processedAt', Db::prepareDateForDb((clone $now)->modify('-60 days'))],
        ]);

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * Date options for a date-mode trigger: the element type's custom Date
     * fields (gathered from its field layouts) plus its queryable native date
     * attributes, separated by optgroup markers. Feeds the "The date" select —
     * everything offered here is guaranteed to work as a query param in
     * findCandidates() and resolve via resolveDateValue().
     *
     * @param class-string<ElementInterface> $elementType
     * @return array<int,array{label?:string,value?:string,optgroup?:string}>
     */
    public function getDateFieldOptions(string $elementType): array
    {
        $fields = [];
        foreach (Craft::$app->getFields()->getLayoutsByType($elementType) as $layout) {
            foreach ($layout->getCustomFields() as $field) {
                if ($field instanceof Date) {
                    $fields[$field->handle] = $field->name;
                }
            }
        }
        asort($fields);

        // Commerce classes stay strings — it's not a dependency, so there's
        // nothing to import (same convention as EventRegistry::commerceTriggers()).
        $native = [
            Entry::class => ['postDate' => 'Post Date', 'expiryDate' => 'Expiry Date'],
            User::class => ['lastLoginDate' => 'Last Login Date'],
            Asset::class => ['dateModified' => 'File Modification Date'],
            'craft\commerce\elements\Order' => ['dateOrdered' => 'Date Ordered', 'datePaid' => 'Date Paid'],
            'craft\commerce\elements\Subscription' => ['nextPaymentDate' => 'Next Payment Date', 'dateExpired' => 'Date Expired'],
        ];
        $attributes = ($native[$elementType] ?? []) + [
            'dateCreated' => 'Date Created',
            'dateUpdated' => 'Date Updated',
        ];

        $options = [];
        if ($fields) {
            $options[] = ['optgroup' => Craft::t('courier', 'Date fields')];
            foreach ($fields as $handle => $name) {
                $options[] = ['label' => $name, 'value' => $handle];
            }
            $options[] = ['optgroup' => Craft::t('courier', 'Attributes')];
        }
        foreach ($attributes as $handle => $label) {
            $options[] = ['label' => Craft::t('courier', $label), 'value' => $handle];
        }

        return $options;
    }

    /** Stamp + read the scheduler heartbeat (drives the CP misconfiguration banner). */
    public function stampHeartbeat(): void
    {
        Craft::$app->getCache()->set(self::HEARTBEAT_CACHE_KEY, time(), 0);
    }

    public function getHeartbeat(): ?int
    {
        $value = Craft::$app->getCache()->get(self::HEARTBEAT_CACHE_KEY);
        return $value ? (int) $value : null;
    }

    /** Whether any enabled date triggers exist (gates the heartbeat banner). */
    public function hasDateTriggers(): bool
    {
        return (new \craft\db\Query())
            ->from('{{%courier_triggers}}')
            ->innerJoin('{{%elements}} elements', '[[elements.id]] = [[courier_triggers.id]]')
            ->where(['triggerMode' => 'date', 'elements.enabled' => true, 'elements.dateDeleted' => null])
            ->exists();
    }

    /**
     * Why a due row must NOT send, or null if it's still good. The whole point
     * of send-time re-checking: staleness loses, quietly and observably.
     */
    private function recheck(ScheduledSendRecord $row, \DateTime $now): ?string
    {
        $sendAt = DateTimeHelper::toDateTime($row->sendAt);
        if ($sendAt && $sendAt < (clone $now)->modify('-' . self::MAX_STALENESS_HOURS . ' hours')) {
            return 'Send time passed more than ' . self::MAX_STALENESS_HOURS . 'h ago (scheduler outage?) — not sending late.';
        }

        $trigger = Trigger::find()->id($row->triggerId)->status(null)->one();
        if (!$trigger || !$trigger->enabled || !$trigger->isDateMode()) {
            return 'Trigger no longer exists, is disabled, or changed mode.';
        }

        // Once-audience rows (elementId 0) have no element or conditions to
        // re-check — only the trigger's own date and that it still sends once.
        if ((int) $row->elementId === 0) {
            if (!$trigger->isOnceMode()) {
                return 'Trigger no longer sends once on a fixed date.';
            }
            $fixedValue = $this->fixedDateValue($trigger);
            if (!$fixedValue || $fixedValue->format('Y-m-d') !== $row->resolvedDate) {
                return "Date changed since scheduling (was {$row->resolvedDate}).";
            }
            return null;
        }

        $element = $this->loadElement($trigger, $row->elementId);
        if (!$element) {
            return 'Element no longer exists.';
        }

        $dateValue = $this->resolveTriggerDate($trigger, $element);
        if (!$dateValue || $dateValue->format('Y-m-d') !== $row->resolvedDate) {
            return "Date changed since scheduling (was {$row->resolvedDate}).";
        }

        if (!$trigger->matchesConditions($element)) {
            return 'Conditions no longer match.';
        }

        return null;
    }

    /**
     * Elements whose date field puts their send moment near the window. The
     * date-range pre-filter keeps the query cheap; exact math happens per
     * element in scan().
     *
     * @return ElementInterface[]
     */
    private function findCandidates(Trigger $trigger, string $elementType, \DateTime $now): array
    {
        // date = sendAt − offset, so shift the send window by −offset; for
        // "before" offsets widen to [now …] so late entries are caught.
        $offset = $trigger->dateOffsetDays;
        $from = (clone $now)->modify('-' . self::MAX_STALENESS_HOURS . ' hours')->modify(sprintf('%+d days', -$offset));
        $to = (clone $now)->modify('+' . self::HORIZON_HOURS . ' hours')->modify(sprintf('%+d days', -$offset));
        if ($offset < 0 && $from > $now) {
            $from = clone $now;
        }

        $range = [
            'and',
            '>= ' . $from->format(\DateTimeInterface::ATOM),
            '<= ' . $to->format(\DateTimeInterface::ATOM),
        ];

        // Fixed date: there's no per-element date to range-query, so prefilter by
        // the trigger's conditions instead (OR groups = one query each, deduped).
        if ($trigger->fixedDate) {
            $groups = array_filter($trigger->getConditionGroups(), fn($g) => !$g->isEmpty());
            if (empty($groups)) {
                return $elementType::find()->all();
            }
            $byId = [];
            foreach ($groups as $group) {
                $q = $elementType::find();
                $group->modifyQuery($q);
                foreach ($q->all() as $el) {
                    $byId[$el->id] = $el;
                }
            }
            return array_values($byId);
        }

        /** @var \craft\elements\db\ElementQuery $query */
        $query = $elementType::find();

        try {
            // Works for custom date fields and queryable date attributes alike
            // (postDate, dateCreated, lastLoginDate, …).
            $query->{$trigger->dateField}($range);
            return $query->all();
        } catch (\Throwable $e) {
            Craft::warning("Date trigger '{$trigger->handle}': can't query '{$trigger->dateField}' on {$elementType} ({$e->getMessage()}); skipping.", __METHOD__);
            return [];
        }
    }

    /** The date this trigger keys on for an element: the shared fixed date, or the element's own field. */
    private function resolveTriggerDate(Trigger $trigger, ElementInterface $element): ?\DateTimeInterface
    {
        if ($trigger->fixedDate) {
            return $this->fixedDateValue($trigger);
        }
        return $trigger->dateField ? $this->resolveDateValue($element, $trigger->dateField) : null;
    }

    /**
     * A trigger's fixed date as site-timezone midnight, parsed explicitly —
     * DateTimeHelper::toDateTime() assumes UTC for bare date strings, which
     * silently shifts the date a day backward in timezones west of UTC.
     */
    private function fixedDateValue(Trigger $trigger): ?\DateTimeInterface
    {
        if (!$trigger->fixedDate) {
            return null;
        }
        $tz = new \DateTimeZone(Craft::$app->getTimeZone());
        return \DateTime::createFromFormat('!Y-m-d', $trigger->fixedDate, $tz) ?: null;
    }

    private function resolveDateValue(ElementInterface $element, string $dateField): ?\DateTimeInterface
    {
        try {
            $value = $element->$dateField ?? null;
        } catch (\Throwable) {
            $value = null;
        }
        return $value instanceof \DateTimeInterface ? $value : null;
    }

    private function loadElement(?Trigger $trigger, int $elementId): ?ElementInterface
    {
        $elementType = $trigger?->getBoundElementType();
        if (!$elementType) {
            return null;
        }
        return $elementType::find()->id($elementId)->one();
    }

    /** @param int $elementId the element getting the send, or 0 for once-audience rows */
    private function insertPending(Trigger $trigger, int $elementId, \DateTimeInterface $dateValue, \DateTime $sendAt): bool
    {
        $resolvedDate = $dateValue->format('Y-m-d');

        // Pending OR processed — either way this exact send is accounted for.
        // (Processed rows persist as once-per-element-per-date markers.)
        $exists = ScheduledSendRecord::find()
            ->where(['triggerId' => $trigger->id, 'elementId' => $elementId, 'resolvedDate' => $resolvedDate])
            ->exists();
        if ($exists) {
            return false;
        }

        $record = new ScheduledSendRecord();
        $record->triggerId = $trigger->id;
        $record->elementId = $elementId;
        $record->resolvedDate = $resolvedDate;
        $record->sendAt = Db::prepareDateForDb($sendAt);

        return $record->save();
    }
}
