<?php

namespace yellowrobot\courier\controllers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementCondition;
use craft\web\Controller;
use yellowrobot\courier\conditions\CourierSendCondition;
use yellowrobot\courier\elements\EmailTemplate;
use yellowrobot\courier\elements\Trigger;
use yellowrobot\courier\Courier;
use yellowrobot\courier\traits\ResolvesRecipientsTrait;
use yii\web\Response;

class TriggersController extends Controller
{
    use ResolvesRecipientsTrait;

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
        return $this->renderTemplate('courier/triggers/_index', [
            'triggers' => Trigger::find()->status(null)->orderBy(['dateUpdated' => SORT_DESC])->all(),
        ]);
    }

    /**
     * Create a disabled trigger (with its 1:1 template) and open the full edit
     * screen. It persists immediately so the edit screen's Save round-trip works
     * (Craft can't save a never-persisted element — it has no id to post back).
     * A disabled trigger never fires, and deleting it cascades to its template.
     *
     * NOTE: the entry-style "provisional draft" create flow (no row until you
     * type) is the nicer UX but needs nullable schema + draft handling; tracked
     * as a follow-up.
     */
    public function actionCreate(): Response
    {
        $trigger = new Trigger();
        $trigger->title = 'New trigger';
        $trigger->subject = 'New trigger';
        $trigger->eventTrigger = array_key_first(Courier::$plugin->events->getAll()) ?? 'entry.saved';
        $trigger->enabled = false;

        if (!Craft::$app->getElements()->saveElement($trigger)) {
            Craft::$app->getSession()->setError(Craft::t('courier', 'Couldn’t create trigger.'));
            return $this->redirect('courier/triggers');
        }

        return $this->redirect($trigger->getCpEditUrl());
    }

    /**
     * Render the trigger's (possibly unsaved) subject/body against a picked
     * element — or an empty mock when none is chosen — and return HTML/text.
     */
    /**
     * Re-render the condition builder for a given event's element type. The
     * builder is element-type-specific (User → Group rules, Entry → Section/Type/
     * Author, etc.), so when the Event dropdown changes the browser swaps in a
     * fresh builder via this endpoint. Mirrors the channel type-settings swap.
     */
    public function actionConditionBuilder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        // Date-mode triggers send their element type directly; event triggers
        // resolve it from the registry.
        $explicitType = (string) $this->request->getBodyParam('elementType', '');
        if ($explicitType !== '' && is_subclass_of($explicitType, \craft\base\ElementInterface::class)) {
            $elementType = $explicitType;
        } else {
            $eventTrigger = (string) $this->request->getBodyParam('eventTrigger', '');
            $elementType = Courier::$plugin->events->getElementType($eventTrigger);
        }

        // Each OR group is its own namespaced builder (conditionGroups[N]) so they
        // round-trip independently through core's ConditionsController.
        $groupIndex = $this->request->getBodyParam('groupIndex');

        $condition = new CourierSendCondition();
        if ($elementType) {
            $condition->elementType = $elementType;
        }
        $condition->mainTag = 'div';
        $condition->id = "courier-condition-{$groupIndex}";
        $condition->name = "conditionGroups[{$groupIndex}]";

        $view = Craft::$app->getView();
        $html = $condition->getBuilderHtml();

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    /**
     * Date options for a date-mode trigger's element type (custom Date fields +
     * queryable date attributes). The browser repopulates the "The date" select
     * from this when the element type changes.
     */
    public function actionDateFieldOptions(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $elementType = (string) $this->request->getBodyParam('elementType', '');
        $options = is_subclass_of($elementType, ElementInterface::class)
            ? Courier::$plugin->scheduler->getDateFieldOptions($elementType)
            : [];

        return $this->asJson(['options' => $options]);
    }

    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $subject = (string) $request->getBodyParam('subject', '');
        $htmlBody = (string) $request->getBodyParam('htmlBody', '');
        $textBody = (string) $request->getBodyParam('textBody', '');
        $handle = (string) $request->getBodyParam('handle', '');

        $variables = $this->resolvePreviewVariables(
            $request->getBodyParam('previewElementId'),
            $request->getBodyParam('previewElementType'),
            $request->getBodyParam('condition'),
            $request->getBodyParam('conditions'),
        );

        $template = new EmailTemplate();
        $template->subject = $subject;
        $template->htmlBody = $htmlBody;
        $template->textBody = $textBody ?: null;

        try {
            $rendered = Courier::$plugin->email->render($handle, $template, $variables);
        } catch (\Throwable $e) {
            Craft::error("Trigger preview render failed: {$e->getMessage()}", __METHOD__);
            return $this->asJson(['success' => false, 'error' => 'Template error: ' . $this->formatRenderError($e)]);
        }

        // Resolve the trigger's recipient expressions against the sample, so the
        // preview shows who it would actually address (not just the body).
        return $this->asJson([
            'success' => true,
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            'recipients' => $this->renderRecipientList($request->getBodyParam('recipients') ?: null, $variables),
            'cc' => $this->renderRecipientList($request->getBodyParam('cc') ?: null, $variables),
            'bcc' => $this->renderRecipientList($request->getBodyParam('bcc') ?: null, $variables),
        ]);
    }

    /**
     * Send a test of the (possibly unsaved) content through the trigger's
     * channels. Email is redirected to the current user (private); every other
     * channel fires its real destination (Slack posts to its channel, webhooks
     * hit their URL, SMS texts its configured number) — for those, the live
     * send is the only faithful preview. All are logged with isTest=1.
     */
    public function actionSendTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $triggerId = (int) $request->getBodyParam('triggerId');
        if (!$triggerId) {
            return $this->asJson(['success' => false, 'message' => 'Save the trigger first.']);
        }

        /** @var Trigger|null $trigger */
        $trigger = Trigger::find()->id($triggerId)->status(null)->one();
        if (!$trigger) {
            return $this->asJson(['success' => false, 'message' => 'Trigger not found.']);
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->email) {
            return $this->asJson(['success' => false, 'message' => 'Your account has no email address.']);
        }

        $variables = $this->resolvePreviewVariables(
            $request->getBodyParam('previewElementId'),
            $request->getBodyParam('previewElementType'),
            $request->getBodyParam('condition'),
            $request->getBodyParam('conditions'),
        );

        $template = new EmailTemplate();
        $template->subject = (string) $request->getBodyParam('subject', '');
        $template->htmlBody = (string) $request->getBodyParam('htmlBody', '');
        $template->textBody = ((string) $request->getBodyParam('textBody', '')) ?: null;

        try {
            $rendered = Courier::$plugin->email->render((string) $trigger->handle, $template, $variables);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => 'Template error: ' . $this->formatRenderError($e)]);
        }

        $channels = Courier::$plugin->channels;
        $log = Courier::$plugin->log;

        // Optionally limit the test to a single channel (empty = all).
        $onlyChannelUid = (string) $request->getBodyParam('channelUid', '');

        $parts = [];
        $anySent = false;
        foreach ($trigger->getChannelUids() as $uid) {
            if ($onlyChannelUid !== '' && $uid !== $onlyChannelUid) {
                continue;
            }
            $config = $channels->getConfigByUid($uid);
            if (!$config) {
                continue;
            }

            if (in_array($config->type, ['email', 'smtp'], true)) {
                // Redirect email to the tester — never the live recipient list.
                $message = ['to' => $user->email, 'cc' => [], 'bcc' => []];
                $destLabel = $user->email;
            } else {
                // Self-addressed channels (Slack/Webhook) ignore `to` and fire their
                // own destination; SMS and others resolve the trigger's recipients.
                $message = [
                    'to' => $this->renderRecipientList($trigger->recipients, $variables) ?: '',
                    'cc' => $this->renderRecipientList($trigger->cc, $variables),
                    'bcc' => $this->renderRecipientList($trigger->bcc, $variables),
                ];
                $destLabel = null;
            }

            $message += [
                'subject' => $rendered['subject'],
                'html' => $rendered['html'],
                'text' => $rendered['text'],
            ];

            $result = $channels->send($config, $message);
            $recipient = $destLabel ?? ($result['recipient'] !== '' ? $result['recipient'] : $config->name);

            $log->logSend(
                $trigger->uid,
                (string) $trigger->handle,
                $config->handle,
                $recipient,
                $rendered['subject'],
                $result['success'] ? 'sent' : 'failed',
                $result['error'] ?? null,
                true,
            );

            $parts[] = $result['success']
                ? "{$config->name} → {$recipient}: sent"
                : "{$config->name}: failed (" . ($result['error'] ?? 'error') . ')';
            $anySent = $anySent || $result['success'];
        }

        if (!$parts) {
            return $this->asJson(['success' => false, 'message' => 'This trigger has no channels to test.']);
        }

        $lead = $anySent ? 'Test sent — ' : 'Test failed — ';
        return $this->asJson(['success' => $anySent, 'message' => $lead . implode('; ', $parts) . '.']);
    }

    /**
     * Build render variables from a picked element, or an empty mock of the
     * given type when none is chosen. Mirrors the runtime fire path
     * (`object` plus a short-name alias, e.g. `user`/`entry`).
     *
     * @return array<string,mixed>
     */
    /**
     * Build a readable message from a render failure. For a Twig error, use its
     * structured data — raw message, template line, and source — to show the cause
     * plus the offending source line.
     */
    private function formatRenderError(\Throwable $e): string
    {
        // A wrapped render may nest the Twig error; find it in the chain.
        $twig = $e;
        while ($twig !== null && !$twig instanceof \Twig\Error\Error) {
            $twig = $twig->getPrevious();
        }
        if (!$twig instanceof \Twig\Error\Error) {
            return $e->getMessage();
        }

        $line = $twig->getTemplateLine();
        $out = $twig->getRawMessage() . ($line > 0 ? " (line {$line})" : '');

        // Append the offending source line.
        $source = $twig->getSourceContext();
        if ($source !== null && $line > 0) {
            $offending = explode("\n", $source->getCode())[$line - 1] ?? null;
            if ($offending !== null && trim($offending) !== '') {
                $out .= "\n\n{$line} | " . rtrim($offending);
            }
        }

        return $out;
    }

    private function resolvePreviewVariables(mixed $elementId, mixed $elementType, mixed $conditionConfig = null, mixed $conditionConfigs = null): array
    {
        $elementType = is_string($elementType) && $elementType !== '' ? $elementType : null;
        if (!$elementType || !is_subclass_of($elementType, Element::class)) {
            return [];
        }

        // An explicitly picked element always wins.
        $object = $elementId
            ? Craft::$app->getElements()->getElementById((int) $elementId, $elementType)
            : null;

        // Nothing picked → auto-pick a representative real element so tokens
        // resolve to real values instead of rendering blank.
        if (!$object) {
            $object = $this->autoPickSample($elementType, $conditionConfig, $conditionConfigs);
        }

        // Last resort: an empty mock so the layout/subject still render.
        if (!$object) {
            $object = new $elementType();
        }

        $alias = strtolower((new \ReflectionClass($object))->getShortName());
        return ['object' => $object, $alias => $object];
    }

    /**
     * Pick a real element to preview/test against, preferring one that matches
     * the trigger's condition (i.e. one that would actually fire it), then the
     * most recent element of the type. Returns null if the type has no elements.
     *
     * @param class-string<ElementInterface> $elementType
     */
    private function autoPickSample(string $elementType, mixed $conditionConfig, mixed $conditionConfigs = null): ?ElementInterface
    {
        // Normalize to a list of OR-combined group configs. Prefer the full list;
        // fall back to the single (legacy) config so older callers still work.
        $groups = [];
        if (is_array($conditionConfigs)) {
            foreach ($conditionConfigs as $cfg) {
                if (is_array($cfg) && $cfg !== []) {
                    $groups[] = $cfg;
                }
            }
        }
        if (!$groups && is_array($conditionConfig) && $conditionConfig !== []) {
            $groups[] = $conditionConfig;
        }

        // 1) Union of per-group matches. Each group is a normal AND condition, so
        // its modifyQuery is valid; OR across groups = the newest element matching
        // ANY group. (This sidesteps the "one query can't express OR" problem —
        // each group gets its own query and we union the winners.)
        $picks = [];
        foreach ($groups as $cfg) {
            try {
                $condition = Craft::$app->getConditions()->createCondition($cfg);
                if ($condition instanceof ElementCondition) {
                    $query = $elementType::find()->status(null);
                    $condition->modifyQuery($query);
                    $match = $query->orderBy(['elements.dateCreated' => SORT_DESC])->one();
                    if ($match) {
                        $picks[] = $match;
                    }
                }
            } catch (\Throwable) {
                // Bad/partial group — skip it, try the others.
            }
        }
        if ($picks) {
            usort($picks, fn(ElementInterface $a, ElementInterface $b) => $b->dateCreated <=> $a->dateCreated);
            return $picks[0];
        }

        // 2) No condition (or nothing matched): most recent element of the type.
        return $elementType::find()->status(null)->orderBy(['elements.dateCreated' => SORT_DESC])->one();
    }
}
