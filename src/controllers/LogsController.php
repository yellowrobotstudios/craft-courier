<?php

namespace yellowrobot\courier\controllers;

use Craft;
use craft\web\Controller;
use yellowrobot\courier\Courier;

class LogsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        return parent::beforeAction($action);
    }

    public function actionIndex(): \yii\web\Response
    {
        $this->requirePermission('courier:manage');

        $page = max(1, (int) Craft::$app->getRequest()->getQueryParam('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $filter = Craft::$app->getRequest()->getQueryParam('status');
        if (!in_array($filter, ['sent', 'failed', 'test'], true)) {
            $filter = null;
        }

        $logs = Courier::$plugin->log->getRecentLogs($limit, $offset, $filter);
        $total = Courier::$plugin->log->getTotalCount($filter);
        $counts = Courier::$plugin->log->getFilterCounts();

        // Viewing the list counts as seeing recent failures — clear the nav badge.
        Courier::$plugin->log->markLogsViewed();

        return $this->renderTemplate('courier/logs/_index', [
            'logs' => $logs,
            'total' => $total,
            'counts' => $counts,
            'filter' => $filter,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function actionDetail(int $id): \yii\web\Response
    {
        $this->requirePermission('courier:manage');

        $log = Courier::$plugin->log->getLogById($id);

        if (!$log) {
            throw new \yii\web\NotFoundHttpException('Log entry not found');
        }

        // Resend is not available in this version (the old re-fire path was tied
        // to config hooks). Trigger-based resend is a future enhancement.
        $canResend = false;

        return $this->renderTemplate('courier/logs/_detail', [
            'log' => $log,
            'canResend' => $canResend,
        ]);
    }

    public function actionResend(): \yii\web\Response
    {
        $this->requirePermission('courier:manage');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return $this->asJson([
            'success' => false,
            'error' => 'Resend isn’t available in this version.',
        ]);
    }

    public function actionDelete(): \yii\web\Response
    {
        $this->requirePermission('courier:manage');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        Courier::$plugin->log->deleteLog($id);

        return $this->asSuccess('Log entry deleted.');
    }

    public function actionClear(): \yii\web\Response
    {
        $this->requirePermission('courier:manage');
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $scope = Craft::$app->getRequest()->getBodyParam('scope', 'all');
        $count = $scope === 'tests'
            ? Courier::$plugin->log->clearTests()
            : Courier::$plugin->log->clearAll();

        $noun = $count === 1 ? 'entry' : 'entries';
        return $this->asSuccess("Cleared {$count} log {$noun}.");
    }
}
