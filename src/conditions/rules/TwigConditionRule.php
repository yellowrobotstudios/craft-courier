<?php

namespace yellowrobot\courier\conditions\rules;

use Craft;
use craft\base\conditions\BaseConditionRule;
use craft\base\ElementInterface;
use craft\elements\conditions\ElementConditionRuleInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Cp;
use craft\helpers\Html;

/**
 * A condition rule that matches when a Twig expression evaluates truthy against
 * the bound element. It's the in-builder equivalent of the trigger's standalone
 * Twig condition — but as a rule it participates in the group's AND (and, across
 * groups, the OR), instead of being a single global gate.
 *
 * The expression is full Twig rendered against the element (`object`), e.g.
 * `{% if object.x == 'y' %}yes{% endif %}` or `{{ object.x == 'y' }}`; it matches
 * when the output is true / 1 / on / yes. Same model as Craft's own Twig fields.
 *
 * It can't be expressed as SQL, so modifyQuery() is a no-op: the expression is
 * checked against the element after the query runs (matchElement), like Craft's
 * own non-queryable rules.
 */
class TwigConditionRule extends BaseConditionRule implements ElementConditionRuleInterface
{
    public string $expression = '';

    public function getLabel(): string
    {
        return Craft::t('courier', 'Twig condition');
    }

    public function getExclusiveQueryParams(): array
    {
        // No query params — the rule doesn't touch the element query, so it never
        // collides with others (and you can add more than one per group).
        return [];
    }

    public function getConfig(): array
    {
        return array_merge(parent::getConfig(), [
            'expression' => $this->expression,
        ]);
    }

    protected function inputHtml(): string
    {
        $input = Html::hiddenLabel(Html::encode($this->getLabel()), 'expression') .
            Cp::textHtml([
                'type' => 'text',
                'id' => 'expression',
                'name' => 'expression',
                'value' => $this->expression,
                'placeholder' => "{{ object.section.handle == 'news' }}",
                'autocomplete' => false,
                'class' => ['flex-grow', 'code'],
            ]);

        $hint = Craft::t('courier', 'Matches when this Twig evaluates to true. `object` is the element.');

        return Html::tag('div', $input, ['class' => 'fullwidth']) .
            Html::tag('div', $hint, ['class' => ['smalltext', 'light']]);
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['expression'], 'safe'],
        ]);
    }

    public function modifyQuery(ElementQueryInterface $query): void
    {
        // Not expressible as SQL — evaluated against the element in matchElement().
    }

    public function matchElement(ElementInterface $element): bool
    {
        $expr = trim($this->expression);
        if ($expr === '') {
            return true;
        }

        // Full Twig rendered against the element (`object`): e.g.
        // {% if object.x == 'y' %}yes{% endif %} or {{ object.x == 'y' }}.
        // Matches when it outputs true / 1 / on / yes.
        try {
            $out = trim(Craft::$app->getView()->renderObjectTemplate($expr, $element));
            return filter_var($out, FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable $e) {
            Craft::error("Courier Twig condition rule failed: {$e->getMessage()}", __METHOD__);
            return false;
        }
    }
}
