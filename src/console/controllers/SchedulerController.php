<?php

namespace yellowrobot\courier\console\controllers;

use Craft;
use craft\console\Controller;
use yellowrobot\courier\Courier;
use yii\console\ExitCode;

/**
 * Time-shifted sending: scans date triggers and promotes due scheduled sends.
 *
 * Run every minute via cron:
 *
 *     * * * * * php craft courier/scheduler/run
 *
 * Fast no-op when nothing is due; mutex-locked so overlapping runs can't
 * double-send. Sites that only use event triggers don't need this at all.
 */
class SchedulerController extends Controller
{
    /**
     * Scan date triggers and send anything due.
     */
    public function actionRun(): int
    {
        $mutex = Craft::$app->getMutex();
        $lockName = 'courier:scheduler';

        if (!$mutex->acquire($lockName)) {
            $this->stdout("Another scheduler run is in progress; skipping.\n");
            return ExitCode::OK;
        }

        try {
            $scheduler = Courier::$plugin->scheduler;

            $inserted = $scheduler->scan();
            $result = $scheduler->promote();
            $scheduler->stampHeartbeat();

            $this->stdout("scheduled={$inserted} sent={$result['sent']} skipped={$result['skipped']}\n");
        } finally {
            $mutex->release($lockName);
        }

        return ExitCode::OK;
    }
}
