<?php

namespace yellowrobot\courier\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use yellowrobot\courier\elements\db\EmailTemplateQuery;
use yellowrobot\courier\records\EmailTemplateRecord;

class EmailTemplate extends Element
{
    public ?string $handle = null;
    public ?string $subject = null;
    public ?string $htmlBody = null;
    public ?string $textBody = null;
    public ?string $bodyFile = null;

    /**
     * The site-template name of an active body-file override, or null if the
     * DB body is canonical. Precedence: explicit bodyFile → conventional path.
     */
    public function getBodyFileTemplate(): ?string
    {
        $view = Craft::$app->getView();

        if ($this->bodyFile) {
            $name = preg_replace('/\.twig$/', '', $this->bodyFile);
            if ($view->doesTemplateExist($name, \craft\web\View::TEMPLATE_MODE_SITE)) {
                return $name;
            }
        }

        if ($this->handle) {
            $conventional = "_courier/{$this->handle}";
            if ($view->doesTemplateExist($conventional, \craft\web\View::TEMPLATE_MODE_SITE)) {
                return $conventional;
            }
        }

        return null;
    }

    public function isBodyFileManaged(): bool
    {
        return $this->getBodyFileTemplate() !== null;
    }

    public static function displayName(): string
    {
        return 'Email Template';
    }

    public static function pluralDisplayName(): string
    {
        return 'Email Templates';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function find(): EmailTemplateQuery
    {
        return new EmailTemplateQuery(static::class);
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return self::createFieldLayout();
    }

    public static function createFieldLayout(): FieldLayout
    {
        $layout = new FieldLayout();
        $layout->type = static::class;

        $tab = new \craft\models\FieldLayoutTab();
        $tab->name = 'Content';
        $tab->setLayout($layout);
        $tab->setElements([
            new \craft\fieldlayoutelements\TitleField(),
            new \craft\fieldlayoutelements\TextField([
                'attribute' => 'subject',
                'label' => 'Subject',
                'instructions' => 'Twig is supported. E.g., `Welcome, {{ name }}!`',
                'required' => true,
            ]),
            new \yellowrobot\courier\fieldlayoutelements\TextareaField([
                'attribute' => 'htmlBody',
                'label' => 'HTML Body',
                'instructions' => 'Twig template for the HTML email body.',
                'required' => true,
                'rows' => 16,
                'code' => true,
            ]),
            new \yellowrobot\courier\fieldlayoutelements\TextareaField([
                'attribute' => 'textBody',
                'label' => 'Plain Text Body',
                'instructions' => 'Optional. If blank, plain text will be auto-generated from the HTML body.',
                'required' => false,
                'rows' => 8,
                'code' => true,
            ]),
        ]);

        $layout->setTabs([$tab]);

        return $layout;
    }

    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('courier', 'All Templates'),
                'criteria' => [],
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'handle' => Craft::t('courier', 'Handle'),
            'subject' => Craft::t('courier', 'Subject'),
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['title', 'handle', 'subject', 'dateUpdated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'handle' => Craft::t('courier', 'Handle'),
            'dateCreated' => Craft::t('app', 'Date Created'),
            'dateUpdated' => Craft::t('app', 'Date Updated'),
        ];
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['handle', 'subject'];
    }

    protected function attributeHtml(string $attribute): string
    {
        if ($attribute === 'title') {
            $url = $this->getCpEditUrl();
            return Html::a(Html::encode($this->title), $url);
        }

        return parent::attributeHtml($attribute);
    }

    protected static function defineActions(string $source): array
    {
        return [
            Delete::class,
        ];
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        // htmlBody deliberately not required: a just-created (disabled) trigger
        // legitimately has no body yet — the column stores '' rather than a
        // placeholder tag leaking into the editor.
        $rules[] = [['handle', 'subject'], 'required'];
        $rules[] = [['handle'], 'string', 'max' => 255];
        // Accept camelCase (trigger-linked templates) and legacy kebab-case handles
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'];
        $rules[] = [['subject'], 'string', 'max' => 255];

        return $rules;
    }

    public function canView(\craft\elements\User $user): bool
    {
        if (parent::canView($user)) {
            return true;
        }
        // Content editors (edit-templates) may view/edit template copy; admins (manage) too.
        return $user->can('courier:manage') || $user->can('courier:edit-templates');
    }

    protected static function includeSetStatusAction(): bool
    {
        return false;
    }

    public function canSave(\craft\elements\User $user): bool
    {
        if (parent::canSave($user)) {
            return true;
        }
        return $user->can('courier:manage') || $user->can('courier:edit-templates');
    }

    public function canDuplicate(\craft\elements\User $user): bool
    {
        if (parent::canDuplicate($user)) {
            return true;
        }
        return $user->can('courier:manage');
    }

    public function canCreateDrafts(\craft\elements\User $user): bool
    {
        return false;
    }

    public function canDelete(\craft\elements\User $user): bool
    {
        if (parent::canDelete($user)) {
            return true;
        }
        return $user->can('courier:manage');
    }

    public function getUriFormat(): ?string
    {
        return null;
    }

    protected function previewTargets(): array
    {
        return [];
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("courier/edit/{$this->id}");
    }

    // ─── Sidebar ───────────────────────────────────────────────

    protected function metaFieldsHtml(bool $static): string
    {
        return parent::metaFieldsHtml($static);
    }

    protected function metadata(): array
    {
        $metadata = [];

        if ($this->handle) {
            $metadata[Craft::t('courier', 'Handle')] = Html::tag('code', Html::encode($this->handle));
        }
        if ($this->isBodyFileManaged()) {
            $metadata[Craft::t('courier', 'Body')] = Html::tag('span', Craft::t('courier', 'Managed in file: {file}', ['file' => (string) $this->getBodyFileTemplate()]));
        }

        return $metadata;
    }

    // ─── Persistence ───────────────────────────────────────────

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = EmailTemplateRecord::findOne($this->id) ?? new EmailTemplateRecord();
            $record->id = $this->id;
            $record->handle = $this->handle;
            $record->subject = $this->subject;
            $record->htmlBody = $this->htmlBody;
            $record->textBody = $this->textBody;
            $record->bodyFile = $this->bodyFile;
            $record->save(false);
        }

        parent::afterSave($isNew);
    }
}
