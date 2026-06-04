<?php

namespace yellowrobot\courier\elements;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\actions\Delete;
use craft\elements\User;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use yellowrobot\courier\conditions\CourierSendCondition;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\db\TriggerQuery;
use yellowrobot\courier\records\TriggerRecord;

/**
 * A Trigger is a hook stored in the database: the event-to-notification wiring,
 * editable in the CP. It owns exactly one Template (1:1).
 */
class Trigger extends Element
{
    public ?string $handle = null;
    /** 'event' (fires on a registry/raw event) or 'date' (fires off a date field via the scheduler). */
    public string $triggerMode = 'event';
    public ?string $eventTrigger = null;
    public ?string $rawEventClass = null;
    public ?string $rawEventName = null;
    public ?string $dateElementType = null;
    public ?string $dateField = null;
    /** Signed: -3 = three days before the date, +7 = a week after. */
    public int $dateOffsetDays = 0;
    /** Time of day to send, site timezone ('09:00'). Null = 09:00. */
    public ?string $dateSendTime = null;
    /** Alternative to dateField: one shared Y-m-d date for every matching element. */
    public ?string $fixedDate = null;
    /**
     * Fixed-date triggers only: 'elements' = one send per matching element;
     * 'once' = a single send to the recipients list, no element context.
     */
    public string $dateAudience = 'elements';
    public ?string $condition = null;
    public ?string $recipients = null;
    public ?string $cc = null;
    public ?string $bcc = null;
    public ?string $variables = null;
    /** @var mixed array of channel uids; stored as JSON (untyped to tolerate raw DB string on populate) */
    public $channelIds = [];
    public string $sendMode = 'list';
    public ?int $templateId = null;

    // Content of the linked 1:1 template, edited inline on the trigger screen.
    // Populated from the template by TriggerQuery; written back in afterSave.
    public ?string $subject = null;
    public ?string $htmlBody = null;
    public ?string $textBody = null;

    // Aggregates for the index table, populated by TriggerQuery via a grouped
    // LEFT JOIN onto the logs table (avoids per-row N+1 count/order queries).
    public ?int $sendCount = null;
    public ?string $lastFired = null;

    public static function displayName(): string
    {
        return 'Trigger';
    }

    public static function pluralDisplayName(): string
    {
        return 'Triggers';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        // A disabled trigger is a silenced notification — worth a loud red dot,
        // not the default gray (core's default reads as "nothing to see here").
        return [
            self::STATUS_ENABLED => Craft::t('app', 'Enabled'),
            self::STATUS_DISABLED => ['label' => Craft::t('app', 'Disabled'), 'color' => \craft\enums\Color::Red],
        ];
    }

    public static function find(): TriggerQuery
    {
        return new TriggerQuery(static::class);
    }

    /** @return string[] channel uids */
    public function getChannelUids(): array
    {
        if (is_string($this->channelIds)) {
            $decoded = $this->channelIds !== '' ? Json::decodeIfJson($this->channelIds) : [];
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($this->channelIds) ? array_values($this->channelIds) : [];
    }

    public function getTemplate(): ?EmailTemplate
    {
        return $this->templateId ? EmailTemplate::find()->id($this->templateId)->status(null)->one() : null;
    }

    public function isDateMode(): bool
    {
        return $this->triggerMode === 'date';
    }

    /**
     * A fixed-date trigger that sends once to its recipients list — no element
     * iteration, no conditions, templates render without `object`.
     */
    public function isOnceMode(): bool
    {
        return $this->isDateMode() && $this->fixedDate && $this->dateAudience === 'once';
    }

    /**
     * The element type this trigger is bound to — from the date config in date
     * mode, or the event registry in event mode. Scopes the condition builder
     * and the preview sample picker.
     *
     * @return class-string<ElementInterface>|null
     */
    public function getBoundElementType(): ?string
    {
        if ($this->isOnceMode()) {
            return null;
        }
        if ($this->isDateMode()) {
            $type = $this->dateElementType;
            return $type && is_subclass_of($type, ElementInterface::class) ? $type : null;
        }
        return $this->eventTrigger
            ? Courier::$plugin->events->getElementType($this->eventTrigger)
            : null;
    }

    /**
     * The visual condition as a list of OR-combined groups. Each group is its own
     * native CourierSendCondition (its rules AND-combined by core); the trigger
     * fires if ANY group matches. Stored as {"groups":[cfg,...]}; a bare legacy
     * config (single condition) is read transparently as one group.
     *
     * @return CourierSendCondition[]
     */
    public function getConditionGroups(): array
    {
        $conditionsService = Craft::$app->getConditions();
        $decoded = $this->condition ? Json::decodeIfJson($this->condition) : null;

        $configs = [];
        if (is_array($decoded)) {
            if (isset($decoded['groups']) && is_array($decoded['groups'])) {
                $configs = $decoded['groups'];
            } elseif (!empty($decoded)) {
                $configs = [$decoded]; // legacy single-condition value
            }
        }

        $elementType = $this->getBoundElementType();

        $groups = [];
        foreach ($configs as $config) {
            if (!is_array($config) || empty($config)) {
                continue;
            }
            /** @var CourierSendCondition $condition */
            $condition = $conditionsService->createCondition($config);
            if ($elementType) {
                $condition->elementType = $elementType;
            }
            $groups[] = $condition;
        }

        return $groups;
    }

    /**
     * A single condition builder, scoped to the trigger's element type. Returns
     * the first stored group, or a fresh empty builder. Kept for callers that
     * only deal with one condition; group-aware code uses getConditionGroups().
     */
    public function getConditionBuilder(): CourierSendCondition
    {
        $groups = $this->getConditionGroups();
        if (!empty($groups)) {
            return $groups[0];
        }

        $condition = new CourierSendCondition();
        if ($elementType = $this->getBoundElementType()) {
            $condition->elementType = $elementType;
        }
        return $condition;
    }

    /**
     * Whether an element satisfies the trigger's visual conditions. Groups are
     * OR-combined: no (non-empty) groups means no gate (true); otherwise true if
     * ANY group matches. Each group AND-combines its own rules natively.
     */
    public function matchesConditions(ElementInterface $element): bool
    {
        $groups = array_filter($this->getConditionGroups(), fn(CourierSendCondition $g) => !$g->isEmpty());
        if (empty($groups)) {
            return true;
        }
        foreach ($groups as $group) {
            if ($group->matchElement($element)) {
                return true;
            }
        }
        return false;
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return self::createFieldLayout();
    }

    public static function createFieldLayout(): FieldLayout
    {
        $layout = new FieldLayout();
        $layout->type = static::class;

        // Tab 1 — Content. "When this fires" (event + conditions) leads, since it's
        // one decision and reads before the message; then the message body. Delivery
        // wiring (channels, recipients) sits in the sidebar via metaFieldsHtml().
        $contentTab = new FieldLayoutTab();
        $contentTab->name = 'Content';
        $contentTab->setLayout($layout);
        $contentTab->setElements([
            new \craft\fieldlayoutelements\TitleField(),
            // Event + conditions, unified — both answer "when does this fire?". The
            // condition builder is wide, so this whole group lives in the main column.
            new \yellowrobot\courier\fieldlayoutelements\ConditionsField(),
            // The variables hint depends on the chosen event and is used by the
            // subject/body below — so it sits between them as a reference.
            new \yellowrobot\courier\fieldlayoutelements\VariablesHintField(),
            new \craft\fieldlayoutelements\TextField([
                'attribute' => 'subject',
                'label' => 'Subject',
                'instructions' => 'Twig is supported, e.g. `Welcome, {{ user.friendlyName }}!`',
                'required' => true,
            ]),
            new \yellowrobot\courier\fieldlayoutelements\TextareaField([
                'attribute' => 'htmlBody',
                'label' => 'HTML Body',
                'instructions' => 'The notification body. Twig is supported.',
                'rows' => 18,
                'code' => true,
            ]),
            new \yellowrobot\courier\fieldlayoutelements\TextareaField([
                'attribute' => 'textBody',
                'label' => 'Plain Text Body',
                'instructions' => 'Optional. If blank, plain text is auto-generated from the HTML body.',
                'rows' => 6,
                'code' => true,
            ]),
        ]);

        // Tab 2 — Preview: a pull surface. Reads the live (unsaved) subject/body
        // from the Content tab's inputs, which stay in the DOM when hidden.
        $previewTab = new FieldLayoutTab();
        $previewTab->name = 'Preview';
        $previewTab->setLayout($layout);
        $previewTab->setElements([
            new \yellowrobot\courier\fieldlayoutelements\PreviewField(),
        ]);

        $layout->setTabs([$contentTab, $previewTab]);

        return $layout;
    }

    protected static function defineSources(string $context): array
    {
        return [
            ['key' => '*', 'label' => Craft::t('courier', 'All Triggers'), 'criteria' => []],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'handle' => Craft::t('courier', 'Handle'),
            'eventTrigger' => Craft::t('courier', 'Event'),
            'channelCount' => Craft::t('courier', 'Channels'),
            'sendCount' => Craft::t('courier', 'Sends'),
            'lastFired' => Craft::t('courier', 'Last fired'),
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'handle', 'eventTrigger', 'channelCount', 'sendCount', 'lastFired'];
    }

    protected static function defineActions(string $source): array
    {
        return [Delete::class];
    }

    protected function attributeHtml(string $attribute): string
    {
        if ($attribute === 'title') {
            return Html::a(Html::encode((string) $this->title), (string) $this->getCpEditUrl());
        }
        if ($attribute === 'eventTrigger') {
            if ($this->isDateMode()) {
                $offset = $this->dateOffsetDays === 0
                    ? Craft::t('courier', 'on')
                    : ($this->dateOffsetDays < 0
                        ? Craft::t('courier', '{n}d before', ['n' => abs($this->dateOffsetDays)])
                        : Craft::t('courier', '{n}d after', ['n' => $this->dateOffsetDays]));
                return Html::tag('code', Html::encode("{$offset} " . ($this->fixedDate ?: $this->dateField)));
            }
            return Html::tag('code', Html::encode((string) $this->eventTrigger));
        }
        if ($attribute === 'channelCount') {
            return (string) count($this->getChannelUids());
        }
        if ($attribute === 'sendCount') {
            // Pre-populated by TriggerQuery's grouped join (no per-row query).
            return (string) ($this->sendCount ?? 0);
        }
        if ($attribute === 'lastFired') {
            // Pre-populated by TriggerQuery's grouped join (no per-row query).
            if (!$this->lastFired) {
                return Html::tag('span', Craft::t('courier', 'Never'), ['class' => 'light']);
            }
            $dt = \craft\helpers\DateTimeHelper::toDateTime($this->lastFired);
            return $dt ? Craft::$app->getFormatter()->asDatetime($dt, 'short') : '—';
        }
        return parent::attributeHtml($attribute);
    }

    /**
     * Derive the handle from the title when one isn't set (Craft-style),
     * ensuring uniqueness with a numeric suffix. Runs before validation so the
     * required-handle rule passes for auto-generated handles.
     */
    public function beforeValidate(): bool
    {
        if (!$this->handle && $this->title) {
            $this->handle = $this->generateUniqueHandle($this->title);
        }

        // The visual condition builder isn't a native field-layout field, so pull
        // its posted config and store the normalized JSON. Guarded to web requests:
        // console/programmatic saves set $this->condition directly.
        //
        // Conditions post as `conditionGroups[N]` — a list of OR-combined builders.
        // Empty groups are dropped so they don't persist as noise. (A single legacy
        // `conditionBuilder` post is still accepted and stored as one group.)
        $request = Craft::$app->getRequest();
        if ($request instanceof \craft\web\Request) {
            // Date-mode offset posts as magnitude + direction (reads better in the
            // UI than a signed integer); recombine into the stored signed value.
            $offsetValue = $request->getBodyParam('dateOffsetValue');
            if ($offsetValue !== null && $offsetValue !== '') {
                $direction = (string) $request->getBodyParam('dateOffsetDirection', 'before');
                $this->dateOffsetDays = abs((int) $offsetValue) * ($direction === 'before' ? -1 : 1);
            }

            // The date-source select decides which of dateField/fixedDate persists.
            // Field dates are inherently per-element, so the audience choice only
            // survives for fixed dates; once-audience needs no element type.
            $dateSource = $request->getBodyParam('dateSource');
            if ($dateSource === 'field') {
                $this->fixedDate = null;
                $this->dateAudience = 'elements';
            } elseif ($dateSource === 'fixed') {
                $this->dateField = null;
                if ($this->dateAudience === 'once') {
                    $this->dateElementType = null;
                }
            }

            $conditionsService = Craft::$app->getConditions();
            $postedGroups = $request->getBodyParam('conditionGroups');

            if (is_array($postedGroups)) {
                $groups = [];
                foreach ($postedGroups as $groupConfig) {
                    if (!is_array($groupConfig)) {
                        continue;
                    }
                    $condition = $conditionsService->createCondition($groupConfig);
                    if ($condition->isEmpty()) {
                        continue;
                    }
                    $groups[] = $condition->getConfig();
                }
                $this->condition = empty($groups) ? null : Json::encode(['groups' => $groups]);
            } elseif (is_array($posted = $request->getBodyParam('conditionBuilder'))) {
                $condition = $conditionsService->createCondition($posted);
                $this->condition = $condition->isEmpty() ? null : Json::encode(['groups' => [$condition->getConfig()]]);
            }

            // Once-audience sends iterate nothing, so any condition groups still
            // sitting in the (hidden) builder are noise — drop them.
            if ($this->isOnceMode()) {
                $this->condition = null;
            }
        }

        return parent::beforeValidate();
    }

    private function generateUniqueHandle(string $title): string
    {
        $base = StringHelper::toCamelCase($title);
        if ($base === '') {
            $base = 'trigger';
        }

        $handle = $base;
        $suffix = 1;
        while ($this->handleExists($handle)) {
            $suffix++;
            $handle = "{$base}{$suffix}";
        }
        return $handle;
    }

    private function handleExists(string $handle): bool
    {
        $query = self::find()->status(null)->handle($handle);
        if ($this->id) {
            $query->andWhere(['not', ['elements.id' => $this->id]]);
        }
        return $query->exists();
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle'], 'required'];
        $rules[] = [['triggerMode'], 'in', 'range' => ['event', 'date']];
        // Each mode requires its own wiring; the other mode's fields are ignored.
        $rules[] = [['eventTrigger'], 'required', 'when' => fn(self $t) => !$t->isDateMode() && !$t->rawEventClass];
        $rules[] = [['dateElementType'], 'required', 'when' => fn(self $t) => $t->isDateMode() && !$t->isOnceMode()];
        $rules[] = [['dateField'], 'required', 'when' => fn(self $t) => $t->isDateMode() && !$t->fixedDate, 'message' => 'Set a date field, or pick a specific date instead.'];
        $rules[] = [['fixedDate'], 'date', 'format' => 'php:Y-m-d', 'skipOnEmpty' => true];
        $rules[] = [['dateAudience'], 'in', 'range' => ['elements', 'once']];
        $rules[] = [['dateOffsetDays'], 'integer', 'min' => -365, 'max' => 365];
        $rules[] = [['dateSendTime'], 'match', 'pattern' => '/^([01]\d|2[0-3]):[0-5]\d$/', 'message' => 'Send time must be HH:MM (24-hour).', 'skipOnEmpty' => true];
        $rules[] = [['handle'], 'string', 'max' => 255];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, and underscores.'];
        $rules[] = [['triggerMode', 'eventTrigger', 'recipients', 'cc', 'bcc', 'condition', 'variables', 'rawEventClass', 'rawEventName', 'dateElementType', 'dateField', 'dateOffsetDays', 'dateSendTime', 'fixedDate', 'dateAudience', 'sendMode', 'channelIds', 'subject', 'htmlBody', 'textBody'], 'safe'];
        // Catch Twig syntax errors at save time, before they fail at send time.
        $rules[] = [['subject', 'htmlBody', 'textBody'], 'validateTwig'];
        return $rules;
    }

    /**
     * Validate that a Twig field parses (syntax only — does not execute, so
     * undefined variables won't trip it; unclosed tags and bad syntax will).
     */
    public function validateTwig(string $attribute): void
    {
        $code = (string) $this->$attribute;
        if ($code === '') {
            return;
        }

        // These are full Twig templates — validate the raw source as-is.
        $source = $code;

        try {
            $twig = Craft::$app->getView()->getTwig();
            $twig->parse($twig->tokenize(new \Twig\Source($source, $attribute)));
        } catch (\Twig\Error\SyntaxError $e) {
            $label = $this->getAttributeLabel($attribute);
            $this->addError($attribute, "{$label} has a Twig syntax error: " . $e->getRawMessage());
        }
    }

    public function canView(User $user): bool
    {
        if (parent::canView($user)) {
            return true;
        }
        return $user->can('courier:manage');
    }

    public function canSave(User $user): bool
    {
        if (parent::canSave($user)) {
            return true;
        }
        return $user->can('courier:manage');
    }

    public function canDelete(User $user): bool
    {
        if (parent::canDelete($user)) {
            return true;
        }
        return $user->can('courier:manage');
    }

    public function canDuplicate(User $user): bool
    {
        if (parent::canDuplicate($user)) {
            return true;
        }
        return $user->can('courier:manage');
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }

    public function getUriFormat(): ?string
    {
        return null;
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("courier/triggers/edit/{$this->id}");
    }

    public function getPostEditUrl(): ?string
    {
        // After Save, return to the Triggers list rather than the dashboard
        return UrlHelper::cpUrl('courier/triggers');
    }

    /**
     * Right-column (sidebar) fields: the trigger's wiring. The email body owns the
     * main column; everything that decides when/where it sends lives here, mirroring
     * how Craft entries keep settings (status, post date, author) in the sidebar.
     */
    protected function metaFieldsHtml(bool $static): string
    {
        $fields = [
            new \yellowrobot\courier\fieldlayoutelements\HandleField([
                'attribute' => 'handle',
                'label' => 'Handle',
                'instructions' => 'Machine name. Auto-generated from the title; edit to override.',
                'required' => false,
            ]),
            new \yellowrobot\courier\fieldlayoutelements\ChannelsField([
                'attribute' => 'channelIds',
                'label' => 'Channels',
                'instructions' => 'Which configured channels (transports) this sends through. Slack and Webhook are self-addressed and ignore the recipients below.',
                'required' => true,
            ]),
            new \craft\fieldlayoutelements\TextField([
                'attribute' => 'recipients',
                'label' => 'Recipients (To)',
                'instructions' => 'Who this goes to (email: addresses; SMS: phone numbers). Twig + env vars, e.g. `{{ object.email }}` or `$EMAIL_TO`. Comma-separate multiple.',
                'class' => 'code',
            ]),
            new \craft\fieldlayoutelements\TextField([
                'attribute' => 'cc',
                'label' => 'Cc',
                'instructions' => 'Optional. Email channels only.',
                'class' => 'code',
            ]),
            new \craft\fieldlayoutelements\TextField([
                'attribute' => 'bcc',
                'label' => 'Bcc',
                'instructions' => 'Optional. Email channels only.',
                'class' => 'code',
            ]),
        ];

        $html = '';
        foreach ($fields as $field) {
            $html .= $field->formHtml($this, $static) ?? '';
        }

        return $html . parent::metaFieldsHtml($static);
    }

    protected function metadata(): array
    {
        return [
            Craft::t('courier', 'Template') => $this->getTemplate()?->title ?? Html::tag('span', Craft::t('courier', 'Auto-created on save'), ['class' => 'light']),
        ];
    }

    // ─── Persistence ───────────────────────────────────────────

    public function beforeSave(bool $isNew): bool
    {
        // A duplicated trigger must get its own 1:1 template, not share (and
        // overwrite) the original's. Clear the copied FK so syncTemplate creates
        // a fresh one from the duplicate's inline subject/body.
        if ($this->duplicateOf !== null) {
            $this->templateId = null;
        }
        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            // 1:1 template — create or update it with the inline subject/body content
            $this->syncTemplate();

            $record = TriggerRecord::findOne($this->id) ?? new TriggerRecord();
            $record->id = $this->id;
            $record->handle = $this->handle;
            $record->triggerMode = $this->triggerMode ?: 'event';
            $record->eventTrigger = $this->eventTrigger;
            $record->rawEventClass = $this->rawEventClass;
            $record->rawEventName = $this->rawEventName;
            $record->dateElementType = $this->dateElementType;
            $record->dateField = $this->dateField;
            $record->dateOffsetDays = $this->dateOffsetDays;
            $record->dateSendTime = $this->dateSendTime;
            $record->fixedDate = $this->fixedDate ?: null;
            $record->dateAudience = $this->dateAudience ?: 'elements';
            $record->condition = $this->condition;
            $record->recipients = $this->recipients;
            $record->cc = $this->cc;
            $record->bcc = $this->bcc;
            $record->variables = $this->variables;
            $record->channelIds = Json::encode($this->getChannelUids());
            $record->sendMode = $this->sendMode ?: 'list';
            $record->templateId = $this->templateId;
            $record->save(false);

            // Bust the cached listener set
            Courier::$plugin->hook->invalidateTriggerCache();
        }

        parent::afterSave($isNew);
    }

    public function afterDelete(): void
    {
        // Cascade: a trigger owns its 1:1 template, so tear it down together
        if ($this->templateId) {
            $template = EmailTemplate::find()->id($this->templateId)->status(null)->trashed(null)->one();
            if ($template && !$template->trashed) {
                Craft::$app->getElements()->deleteElement($template);
            }
        }

        Courier::$plugin->hook->invalidateTriggerCache();
        parent::afterDelete();
    }

    public function afterRestore(): void
    {
        // Bring the linked template back when the trigger is restored
        if ($this->templateId) {
            $template = EmailTemplate::find()->id($this->templateId)->status(null)->trashed(true)->one();
            if ($template) {
                Craft::$app->getElements()->restoreElement($template);
            }
        }

        Courier::$plugin->hook->invalidateTriggerCache();
        parent::afterRestore();
    }

    /**
     * Create or update the linked 1:1 template from the trigger's inline
     * subject/body fields. The template is a behind-the-scenes record — users
     * edit it here, on the trigger screen, never on its own.
     */
    private function syncTemplate(): void
    {
        $template = $this->templateId
            ? EmailTemplate::find()->id($this->templateId)->status(null)->one()
            : null;

        if (!$template) {
            $template = new EmailTemplate();
            $template->enabled = true;
        }

        $template->title = $this->title ?: 'Notification';
        $template->handle = (string) $this->handle;
        $template->subject = ($this->subject !== null && $this->subject !== '') ? $this->subject : ($this->title ?: 'Notification');
        $template->htmlBody = $this->htmlBody ?? '';
        $template->textBody = ($this->textBody !== null && $this->textBody !== '') ? $this->textBody : null;

        if (Craft::$app->getElements()->saveElement($template)) {
            $this->templateId = $template->id;
        }
    }
}
