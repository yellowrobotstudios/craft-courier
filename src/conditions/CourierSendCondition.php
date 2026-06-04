<?php

namespace yellowrobot\courier\conditions;

use Craft;
use craft\elements\conditions\ElementCondition;
use craft\elements\conditions\entries\AuthorConditionRule;
use craft\elements\conditions\entries\SectionConditionRule;
use craft\elements\conditions\entries\TypeConditionRule;
use craft\elements\conditions\users\AdminConditionRule;
use craft\elements\conditions\users\EmailConditionRule;
use craft\elements\conditions\users\FirstNameConditionRule;
use craft\elements\conditions\users\GroupConditionRule;
use craft\elements\conditions\users\LastLoginDateConditionRule;
use craft\elements\conditions\users\LastNameConditionRule;
use craft\elements\conditions\users\UsernameConditionRule;
use craft\elements\Entry;
use craft\elements\User;
use yellowrobot\courier\conditions\rules\TwigConditionRule;

/**
 * Visual condition builder for a trigger. Extends ElementCondition so authors
 * get Status / Title / custom-field rules for free, plus element-type-specific
 * rules (Section, Entry Type, Author, User Group, Commerce order rules).
 *
 * Evaluated at fire time via matchElement($object).
 */
class CourierSendCondition extends ElementCondition
{
    public string $mainTag = 'div';

    protected function selectableConditionRules(): array
    {
        $rules = parent::selectableConditionRules();

        // Our own Twig escape hatch as a rule — available for any element type so
        // logic the builder can't express lives inside a group (and participates
        // in the AND-within / OR-across-groups logic) rather than as a separate
        // global gate.
        $rules[] = TwigConditionRule::class;

        if ($this->elementType === null || $this->elementType === Entry::class) {
            $rules[] = SectionConditionRule::class;
            $rules[] = TypeConditionRule::class;
            $rules[] = AuthorConditionRule::class;
        }

        if ($this->elementType === null || $this->elementType === User::class) {
            $rules[] = GroupConditionRule::class;
            $rules[] = AdminConditionRule::class;
            $rules[] = EmailConditionRule::class;
            $rules[] = UsernameConditionRule::class;
            $rules[] = FirstNameConditionRule::class;
            $rules[] = LastNameConditionRule::class;
            $rules[] = LastLoginDateConditionRule::class;
        }

        if ($this->isCommerceInstalled()) {
            $orderClass = 'craft\\commerce\\elements\\Order';
            if ($this->elementType === null || $this->elementType === $orderClass) {
                foreach ([
                    'craft\\commerce\\elements\\conditions\\orders\\OrderStatusConditionRule',
                    'craft\\commerce\\elements\\conditions\\orders\\PaidStatusConditionRule',
                    'craft\\commerce\\elements\\conditions\\orders\\TotalPriceConditionRule',
                ] as $rule) {
                    if (class_exists($rule)) {
                        $rules[] = $rule;
                    }
                }
            }
        }

        return $rules;
    }

    public function isEmpty(): bool
    {
        $rules = $this->getConditionRules();
        return is_array($rules) ? count($rules) === 0 : $rules->isEmpty();
    }

    private function isCommerceInstalled(): bool
    {
        return Craft::$app->plugins->isPluginInstalled('commerce')
            && Craft::$app->plugins->isPluginEnabled('commerce');
    }
}
