<?php

namespace yellowrobot\courier\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseUiElement;
use craft\helpers\Html;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\Trigger;
use yellowrobot\courier\web\assets\cp\CourierCpAsset;

/**
 * A small reference panel showing the Twig variables available in the
 * subject/body, based on the trigger's event. Helps authors avoid the
 * "my tokens render empty" trap by naming what actually resolves.
 *
 * Pure display — it carries no posted form value, so it extends BaseUiElement.
 */
class VariablesHintField extends BaseUiElement
{
    /** Curated example fields per element type (by lowercased short class name). */
    private const EXAMPLES = [
        'user' => ['email', 'fullName', 'username', 'friendlyName'],
        'entry' => ['title', 'url', 'slug', 'author.email'],
        'asset' => ['title', 'url', 'filename', 'extension'],
        'category' => ['title', 'url', 'slug'],
        'order' => ['reference', 'email', 'totalPrice'],
    ];

    protected function selectorLabel(): string
    {
        return Craft::t('courier', 'Available variables');
    }

    protected function selectorIcon(): ?string
    {
        return 'brackets-curly';
    }

    public function formHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        if (!$element instanceof Trigger) {
            return null;
        }

        Craft::$app->getView()->registerAssetBundle(CourierCpAsset::class);

        $elementType = $element->eventTrigger
            ? Courier::$plugin->events->getElementType($element->eventTrigger)
            : null;

        if (!$elementType) {
            $body = Html::tag('p', Craft::t('courier', 'This event isn’t tied to an element, so there’s no <code>object</code> to reference. Env vars and static text still work.'), ['class' => 'instructions courier-hint-intro']);
            return $this->panel($body);
        }

        $alias = strtolower((new \ReflectionClass($elementType))->getShortName());
        $typeName = $elementType::displayName();

        $intro = Html::tag('p', Craft::t('courier', 'Subject and body are Twig, rendered against the {type} that fired. Reference it as {object} or its alias {alias}:', [
            'type' => Html::encode($typeName),
            'object' => Html::tag('code', 'object'),
            'alias' => Html::tag('code', Html::encode($alias)),
        ]), [
            'class' => 'courier-hint-intro',
        ]);

        $examples = self::EXAMPLES[$alias] ?? ['id', 'title'];
        $chips = '';
        foreach ($examples as $field) {
            $chips .= Html::tag('code', Html::encode('{{ ' . $alias . '.' . $field . ' }}'), [
                'class' => 'courier-hint-chip',
            ]);
        }
        $chipRow = Html::tag('div', $chips, [
            'class' => 'courier-hint-chip-row',
        ]);

        $note = Html::tag('p', Craft::t('courier', 'Any field or property works, not just these examples.'), [
            'class' => 'courier-hint-note',
        ]);

        return $this->panel($intro . $chipRow . $note);
    }

    private function panel(string $body): string
    {
        // Collapsible (native <details>, open by default) — it's a reference, so
        // it stays visible while composing but can be tucked away once familiar.
        // Link-colored disclosure toggle, so collapsed it reads as a control.
        $summary = Html::tag('summary', Craft::t('courier', 'Available variables'), [
            'class' => 'courier-disclosure',
        ]);
        // Boxed content so the expanded panel reads as a contained unit, not loose
        // text under the heading.
        $content = Html::tag('div', $body, [
            'class' => 'courier-hint-content',
        ]);
        $details = Html::tag('details', $summary . $content, ['open' => true]);
        // No filled card — a top hairline ties it to the body fields above so it
        // reads as part of the form, not a separate band.
        return Html::tag('div', $details, [
            'class' => ['field', 'courier-field-divider--tight'],
        ]);
    }
}
