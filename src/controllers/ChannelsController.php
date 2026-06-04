<?php

namespace yellowrobot\courier\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use yellowrobot\courier\Courier;
use yellowrobot\courier\models\ChannelConfig;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ChannelsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePermission('courier:manage');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('courier/channels/_index', [
            'configs' => Courier::$plugin->channels->getAllConfigs(),
        ]);
    }

    public function actionEdit(?int $id = null): Response
    {
        $channels = Courier::$plugin->channels;

        if ($id !== null) {
            $config = $channels->getConfigById($id);
            if (!$config) {
                throw new NotFoundHttpException('Channel not found');
            }
        } else {
            $config = new ChannelConfig();
            $config->type = array_key_first($channels->getAllTypes()) ?? 'email';
        }

        return $this->renderTemplate('courier/channels/_edit', [
            'config' => $config,
            'typeOptions' => $channels->getTypeOptions(),
            'channelType' => $config->getChannelType(),
        ]);
    }

    /**
     * Render a single channel type's settings form on demand, so the edit
     * screen can swap fields reactively when the type dropdown changes (no save
     * round-trip). Mirrors how Craft re-renders field-type settings.
     */
    public function actionTypeSettings(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $channels = Courier::$plugin->channels;
        $type = $this->request->getRequiredBodyParam('type');
        $channelType = $channels->getTypeByHandle($type);

        if (!$channelType) {
            return $this->asJson(['settingsHtml' => '', 'headHtml' => '', 'bodyHtml' => '']);
        }

        $config = new ChannelConfig();
        $config->type = $type;
        $config->settings = $this->request->getBodyParam('settings', []) ?: [];

        $view = Craft::$app->getView();
        $settingsHtml = $channelType->getSettingsHtml($config);

        return $this->asJson([
            'settingsHtml' => $settingsHtml,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = $this->request;
        $channels = Courier::$plugin->channels;

        $id = $request->getBodyParam('id');
        $config = $id ? $channels->getConfigById((int) $id) : new ChannelConfig();
        if (!$config) {
            throw new NotFoundHttpException('Channel not found');
        }

        $config->name = $request->getBodyParam('name', $config->name);
        $config->handle = $request->getBodyParam('handle', $config->handle);
        $config->type = $request->getBodyParam('type', $config->type);
        $config->enabled = (bool) $request->getBodyParam('enabled', true);
        $config->settings = $request->getBodyParam('settings', []) ?: [];

        if (!$config->handle && $config->name) {
            $config->handle = StringHelper::toCamelCase($config->name);
        }

        if (!$channels->saveConfig($config)) {
            return $this->asModelFailure(
                $config,
                Craft::t('courier', 'Couldn’t save channel.'),
                'config',
            );
        }

        return $this->asModelSuccess(
            $config,
            Craft::t('courier', 'Channel saved.'),
            'config',
        );
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $id = (int) $this->request->getRequiredBodyParam('id');
        $config = Courier::$plugin->channels->getConfigById($id);
        if ($config) {
            Courier::$plugin->channels->deleteConfig($config);
        }
        return $this->asSuccess(Craft::t('courier', 'Channel deleted.'));
    }
}
