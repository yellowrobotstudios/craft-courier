<?php

namespace yellowrobot\courier\services;

use craft\base\Component;
use craft\helpers\Db;
use yellowrobot\courier\records\EmailLogRecord;

class LogService extends Component
{
    public function log(string $templateHandle, string $recipient, string $subject, string $status, ?string $errorMessage = null, ?int $elementId = null, ?string $elementType = null): void
    {
        $record = new EmailLogRecord();
        $record->templateHandle = $templateHandle;
        $record->recipient = $recipient;
        $record->subject = $subject;
        $record->status = $status;
        $record->errorMessage = $errorMessage;
        $record->elementId = $elementId;
        $record->elementType = $elementType;
        $record->dateSent = Db::prepareDateForDb(new \DateTime());
        if (!$record->save()) {
            \Craft::error('Failed to save email log: ' . json_encode($record->getErrors()), __METHOD__);
        }
    }

    /**
     * Record a send from a DB trigger (carries triggerUid, channel, isTest).
     */
    public function logSend(
        ?string $triggerUid,
        string $templateHandle,
        ?string $channel,
        string $recipient,
        string $subject,
        string $status,
        ?string $errorMessage = null,
        bool $isTest = false,
        ?int $elementId = null,
        ?string $elementType = null,
    ): void {
        $record = new EmailLogRecord();
        $record->triggerUid = $triggerUid;
        $record->templateHandle = $templateHandle;
        $record->channel = $channel;
        $record->recipient = $recipient;
        $record->subject = $subject;
        $record->status = $status;
        $record->errorMessage = $errorMessage;
        $record->isTest = $isTest;
        $record->elementId = $elementId;
        $record->elementType = $elementType;
        $record->dateSent = Db::prepareDateForDb(new \DateTime());
        if (!$record->save()) {
            \Craft::error('Failed to save courier log: ' . json_encode($record->getErrors()), __METHOD__);
        }
    }

    /** @return EmailLogRecord[] */
    public function getRecentLogs(int $limit = 50, int $offset = 0, ?string $filter = null): array
    {
        return EmailLogRecord::find()
            ->where($this->filterConditions($filter))
            ->orderBy(['dateSent' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all();
    }

    public function getLogById(int $id): ?EmailLogRecord
    {
        return EmailLogRecord::findOne($id);
    }

    public function getTotalCount(?string $filter = null): int
    {
        return (int) EmailLogRecord::find()
            ->where($this->filterConditions($filter))
            ->count();
    }

    /**
     * Per-tab counts for the Logs screen filter bar.
     *
     * @return array{all:int,sent:int,failed:int,test:int}
     */
    public function getFilterCounts(): array
    {
        return [
            'all' => $this->getTotalCount(),
            'sent' => $this->getTotalCount('sent'),
            'failed' => $this->getTotalCount('failed'),
            'test' => $this->getTotalCount('test'),
        ];
    }

    /**
     * Where-conditions for a Logs filter. Sent/Failed exclude test sends —
     * tests have their own tab (and the nav badge ignores them too).
     */
    private function filterConditions(?string $filter): array
    {
        return match ($filter) {
            'sent' => ['status' => 'sent', 'isTest' => false],
            'failed' => ['status' => 'failed', 'isTest' => false],
            'test' => ['isTest' => true],
            default => [],
        };
    }

    /** User-preference key stamped when the Logs screen is viewed. */
    public const LOGS_VIEWED_PREF = 'courier:logsLastViewed';

    /** Count of real (non-test) failed sends within the last $sinceDays days. */
    public function getFailedCount(int $sinceDays = 7): int
    {
        $since = (new \DateTime())->modify("-{$sinceDays} days");
        return (int) EmailLogRecord::find()
            ->where(['status' => 'failed', 'isTest' => false])
            ->andWhere(['>=', 'dateSent', Db::prepareDateForDb($since)])
            ->count();
    }

    /**
     * Failed sends the current user hasn't seen yet — viewing the Logs screen
     * marks them seen, so the nav badge clears once someone has looked. Still
     * bounded by $sinceDays so old failures age out for users who never visit.
     */
    public function getUnseenFailedCount(int $sinceDays = 7): int
    {
        $since = (new \DateTime())->modify("-{$sinceDays} days");

        $viewedAt = $this->getLogsViewedAt();
        if ($viewedAt && $viewedAt > $since) {
            $since = \DateTime::createFromInterface($viewedAt);
        }

        return (int) EmailLogRecord::find()
            ->where(['status' => 'failed', 'isTest' => false])
            ->andWhere(['>', 'dateSent', Db::prepareDateForDb($since)])
            ->count();
    }

    /** Stamp the current user as having seen the Logs screen (clears their badge). */
    public function markLogsViewed(): void
    {
        if (\Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }
        $user = \Craft::$app->getUser()->getIdentity();
        if ($user) {
            \Craft::$app->getUsers()->saveUserPreferences($user, [
                self::LOGS_VIEWED_PREF => time(),
            ]);
        }
    }

    private function getLogsViewedAt(): ?\DateTimeInterface
    {
        if (\Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }
        $timestamp = \Craft::$app->getUser()->getIdentity()?->getPreference(self::LOGS_VIEWED_PREF);
        return $timestamp ? new \DateTime("@{$timestamp}") : null;
    }

    /** Delete a single log entry. */
    public function deleteLog(int $id): bool
    {
        $record = EmailLogRecord::findOne($id);
        return $record ? (bool) $record->delete() : false;
    }

    /** Delete every log entry. Returns the number removed. */
    public function clearAll(): int
    {
        return EmailLogRecord::deleteAll();
    }

    /** Delete only test-send log entries. Returns the number removed. */
    public function clearTests(): int
    {
        return EmailLogRecord::deleteAll(['isTest' => true]);
    }

    /** Delete log entries older than $days. Returns the number removed. */
    public function pruneOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = (new \DateTime())->modify("-{$days} days");
        return EmailLogRecord::deleteAll(['<', 'dateSent', Db::prepareDateForDb($cutoff)]);
    }
}
