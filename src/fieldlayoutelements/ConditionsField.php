<?php

namespace yellowrobot\courier\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldLayoutElement;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use yellowrobot\courier\conditions\CourierSendCondition;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\Trigger;
use yellowrobot\courier\web\assets\cp\CourierCpAsset;

/**
 * The trigger's "Conditions" group: the event picker plus the visual
 * ElementCondition builder, as OR-combined groups. Twig logic lives inside the
 * builder as a "Twig condition" rule, so there's no separate escape-hatch field.
 *
 * Each group posts as `conditionGroups[N]` (captured in Trigger::beforeValidate).
 *
 * Note: this is NOT a BaseUiElement — it renders interactive inputs whose values
 * are posted back, so it keeps the FieldLayoutElement base. selectorHtml() still
 * returns the BaseUiElement-style wrapped markup for the layout designer.
 */
class ConditionsField extends FieldLayoutElement
{
    public function selectorHtml(): string
    {
        $label = Craft::t('courier', 'When this fires');

        return
            Html::beginTag('div', [
                'class' => 'fld-ui-element',
                'data' => [
                    'type' => str_replace('\\', '-', static::class),
                ],
            ]) .
            Html::beginTag('div', ['class' => 'fld-element-icon']) .
            Cp::fallbackIconSvg($label) .
            Html::endTag('div') . // .fld-element-icon
            Html::beginTag('div', ['class' => 'field-name']) .
            Html::beginTag('div', ['class' => ['fld-element-label']]) .
            Html::tag('h4', Html::encode($label)) .
            Html::endTag('div') . // .fld-element-label
            Html::endTag('div') . // .field-name
            Html::endTag('div'); // .fld-ui-element
    }

    public function formHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        if (!$element instanceof Trigger) {
            return null;
        }

        Craft::$app->getView()->registerAssetBundle(CourierCpAsset::class);

        // OR-combined condition groups. Each is its own namespaced native builder
        // (conditionGroups[N]); the trigger fires if ANY group matches. With none
        // stored, render a single empty group to start from.
        $groups = $element->getConditionGroups();
        if (empty($groups)) {
            $fresh = new CourierSendCondition();
            if ($freshType = $element->getBoundElementType()) {
                $fresh->elementType = $freshType;
            }
            $groups = [$fresh];
        }
        $groups = array_values($groups);

        $heading = Html::tag('div', Craft::t('courier', 'When this fires'), ['class' => 'courier-heading']);

        // Mode: react to an event, or fire off a date field via the scheduler.
        $modeField = Cp::selectFieldHtml([
            'label' => Craft::t('courier', 'Fires'),
            'name' => 'triggerMode',
            'value' => $element->triggerMode,
            'options' => [
                ['label' => Craft::t('courier', 'When an event happens'), 'value' => 'event'],
                ['label' => Craft::t('courier', 'On a date'), 'value' => 'date'],
            ],
        ]);

        // The event and the conditions are one decision ("when?"), so they live in
        // the same section. Event picker on top; the (wide) condition rules below.
        $eventField = (new EventSelectField([
            'attribute' => 'eventTrigger',
            'label' => Craft::t('courier', 'Event'),
            'instructions' => Craft::t('courier', 'The event that fires this notification.'),
            'required' => false,
        ]))->formHtml($element, $static) ?? '';
        $eventBlock = Html::tag('div', $eventField, [
            'id' => 'courier-mode-event',
            'class' => $element->isDateMode() ? ['hidden'] : [],
        ]);

        // The pane plays two roles, and announces the one it's playing. For event
        // and field-date triggers the rules FILTER something that fires anyway;
        // for fixed-date per-element triggers there is no event — the rules ARE
        // the query that builds the audience. JS keeps heading + intro honest.
        [$condHeading, $condIntro] = $this->conditionsCopy($element);
        $conditionsLabel = Html::tag('div', $condHeading, [
            'id' => 'courier-cond-heading',
            'class' => ['courier-heading', 'courier-heading--flush'],
        ]);
        $intro = Html::tag('p', $condIntro, [
            'id' => 'courier-cond-intro',
            'class' => ['instructions', 'courier-cond-intro'],
        ]);

        // Each group is its own namespaced builder block, OR-connected. The "+ Add
        // condition group" button appends more via the condition-builder endpoint.
        $groupsHtml = '';
        foreach ($groups as $i => $group) {
            $groupsHtml .= $this->groupBlockHtml($group, $i);
        }
        $builder = Html::tag('div', $groupsHtml, [
            'id' => 'courier-condition-groups',
            'data' => ['next-index' => count($groups)],
        ]) . Html::button(Craft::t('courier', 'Add condition group'), [
            'type' => 'button',
            'id' => 'courier-add-group',
            'class' => ['btn', 'dashed', 'add', 'icon', 'courier-add-group'],
        ]);

        // Conditions sit in their own boxed pane under the event, so the rules read
        // as a distinct unit rather than flowing in with the event field. (Twig
        // logic now lives inside the builder as a "Twig condition" rule, so there's
        // no separate advanced field.)
        // Hidden in once mode: a single send to the recipients list iterates
        // nothing, so there's nothing for conditions to filter.
        $conditionsBlock = Html::tag('div',
            $conditionsLabel . $intro . $builder,
            [
                'id' => 'courier-conditions-block',
                'class' => array_filter(['pane', 'courier-cond-block', $element->isOnceMode() ? 'hidden' : null]),
            ]
        );

        // Two homes for the conditions block: inside the date pane under "Who
        // gets it?" when it's the audience query (fixed dates), or below as its
        // own pane when it's a filter. Server-rendered into the right slot so
        // nothing jumps on load; JS relocates it when the source flips.
        $condInDatePane = $element->isDateMode() && (bool) $element->fixedDate;

        $dateBlock = Html::tag('div', $this->dateConfigHtml($element, $condInDatePane ? $conditionsBlock : ''), [
            'id' => 'courier-mode-date',
            'class' => $element->isDateMode() ? [] : ['hidden'],
        ]);
        $condMainSlot = Html::tag('div', $condInDatePane ? '' : $conditionsBlock, [
            'id' => 'courier-cond-slot-main',
        ]);

        // Each group is a native, element-type-specific builder. Three behaviors:
        //  • Add group  → fetch a fresh namespaced builder (conditionGroups[N]).
        //  • Remove group → drop the block and its OR connector.
        //  • Event change → element type changes, invalidating rules, so reset to
        //    one fresh group for the new type (mirrors the old single-builder swap).
        // jQuery delegation for the event change — the Event field is a selectize
        // dropdown that fires through jQuery, which a native listener won't see.
        $loadingLabel = Json::encode(Craft::t('courier', 'Loading conditions for this event…'));
        $errorLabel = Json::encode(Craft::t('courier', 'Couldn’t load conditions for this event.'));
        $orLabel = Json::encode(Craft::t('courier', 'or'));
        $removeLabel = Json::encode(Craft::t('courier', 'Remove'));
        $summaryLabels = Json::encode([
            'choose' => Craft::t('courier', 'Choose…'),
            'chooseType' => Craft::t('courier', 'Choose an element type to schedule against.'),
            'placeholderDate' => '…',
            'targetField' => Craft::t('courier', 'each {type}’s “{field}”'),
            'onDay' => Craft::t('courier', 'Sends on {target}, at {time}.'),
            'dayBefore' => Craft::t('courier', 'Sends when 1 day or less remains before {target}, at {time}.'),
            'daysBefore' => Craft::t('courier', 'Sends when {n} days or less remain before {target}, at {time}.'),
            'dayAfter' => Craft::t('courier', 'Sends 1 day after {target}, at {time}.'),
            'daysAfter' => Craft::t('courier', 'Sends {n} days after {target}, at {time}.'),
            'pastNote' => Craft::t('courier', 'Dates already passed never fire.'),
            'noConditions' => Craft::t('courier', 'No conditions — this will send for every {type}.'),
            'noRules' => Craft::t('courier', 'No audience rules — this will send for every {type}.'),
            'perElement' => Craft::t('courier', 'One send for each {type} the audience rules match.'),
            'onceNote' => Craft::t('courier', 'One send, to the recipients listed below.'),
            'condEvent' => [
                'h' => Craft::t('courier', 'Conditions'),
                'i' => Craft::t('courier', 'Fires when <strong>any group</strong> matches. Within a group, every rule must be true. Leave blank to fire on every event.'),
            ],
            'condField' => [
                'h' => Craft::t('courier', 'Conditions'),
                'i' => Craft::t('courier', 'Limit which elements get a send as their date approaches — fires when <strong>any group</strong> matches. Leave blank to include them all.'),
            ],
            'condAudience' => [
                'h' => Craft::t('courier', 'Audience'),
                'i' => Craft::t('courier', 'These rules choose which elements get a send — one apiece, when <strong>any group</strong> matches. Leave blank and every element gets one.'),
            ],
        ]);
        Craft::$app->getView()->registerJs(<<<JS
(function() {
    if (window.__courierConditionGroups) return;
    window.__courierConditionGroups = true;

    var container = document.getElementById('courier-condition-groups');
    var spinner = '<div class="courier-cond-spinner"><div class="spinner"></div><span>' + {$loadingLabel} + '</span></div>';

    function currentEvent() {
        var sel = document.querySelector('select[name="eventTrigger"]');
        return sel ? sel.value : '';
    }

    function currentMode() {
        var sel = document.querySelector('select[name="triggerMode"]');
        return sel ? sel.value : 'event';
    }

    function currentDateType() {
        var sel = document.querySelector('select[name="dateElementType"]');
        return sel ? sel.value : '';
    }

    function currentDateSource() {
        var sel = document.querySelector('select[name="dateSource"]');
        return sel ? sel.value : 'field';
    }

    function currentAudience() {
        var sel = document.querySelector('select[name="dateAudience"]');
        return sel ? sel.value : 'elements';
    }

    function isOnce() {
        return currentMode() === 'date' && currentDateSource() === 'fixed' && currentAudience() === 'once';
    }

    // The element-type select lives in row 1 for field dates ("where the date
    // lives") and in the audience row for fixed dates ("what gets a send").
    function placeTypeSelect() {
        var node = document.getElementById('courier-type-select');
        if (!node) return;
        var fixed = currentDateSource() === 'fixed';
        var fieldSlot = document.getElementById('courier-type-slot-field');
        var audSlot = document.getElementById('courier-type-slot-audience');
        var target = fixed ? audSlot : fieldSlot;
        if (target && node.parentNode !== target) { target.appendChild(node); }
        var audRow = document.getElementById('courier-audience-row');
        if (audRow) { audRow.classList.toggle('hidden', !fixed); }
        if (audSlot) { audSlot.classList.toggle('hidden', !fixed || currentAudience() === 'once'); }
    }

    // A once-send iterates nothing, so the conditions pane has no job to do.
    function toggleConditionsBlock() {
        var block = document.getElementById('courier-conditions-block');
        if (block) { block.classList.toggle('hidden', isOnce()); }
        placeConditionsBlock();
        updateCondCopy();
    }

    // The conditions block lives under "Who gets it?" when it's the audience
    // query (fixed dates), and below as its own pane when it's a filter.
    function placeConditionsBlock() {
        var block = document.getElementById('courier-conditions-block');
        if (!block) return;
        var inDate = currentMode() === 'date' && currentDateSource() === 'fixed';
        var target = document.getElementById(inDate ? 'courier-cond-slot-date' : 'courier-cond-slot-main');
        if (target && block.parentNode !== target) { target.appendChild(block); }
    }

    // The pane is a filter for event/field-date triggers and the audience query
    // for fixed-date ones — heading + intro say which job it's doing.
    function updateCondCopy() {
        var h = document.getElementById('courier-cond-heading');
        var p = document.getElementById('courier-cond-intro');
        if (!h || !p) return;
        var copy;
        if (currentMode() !== 'date') { copy = L.condEvent; }
        else if (currentDateSource() === 'fixed') { copy = L.condAudience; }
        else { copy = L.condField; }
        h.textContent = copy.h;
        p.innerHTML = copy.i;
    }

    var L = {$summaryLabels};

    // Repopulate the "The date" select with the chosen element type's date
    // fields/attributes (keeping the current pick when it survives the swap).
    function refreshDateFieldOptions() {
        var sel = document.querySelector('select[name="dateField"]');
        if (!sel) return;
        var current = sel.value;
        Craft.sendActionRequest('POST', 'courier/triggers/date-field-options', {
            data: {elementType: currentDateType()}
        }).then(function(r) {
            var opts = (r.data && r.data.options) || [];
            sel.innerHTML = '';
            var parent = sel;
            var choose = document.createElement('option');
            choose.value = '';
            choose.textContent = L.choose;
            sel.appendChild(choose);
            opts.forEach(function(o) {
                if (o.optgroup) {
                    parent = document.createElement('optgroup');
                    parent.label = o.optgroup;
                    sel.appendChild(parent);
                } else {
                    var opt = document.createElement('option');
                    opt.value = o.value;
                    opt.textContent = o.label;
                    parent.appendChild(opt);
                }
            });
            if (current && sel.querySelector('option[value="' + CSS.escape(current) + '"]')) {
                sel.value = current;
            }
            updateDateSummary();
        });
    }

    // The "every element on the site" footgun, made visible: a date trigger
    // with no condition rules applies to every element of the type.
    function updateDateWarning() {
        var el = document.getElementById('courier-date-warning');
        if (!el) return;
        var typeSel = document.querySelector('select[name="dateElementType"]');
        var hasRules = document.querySelectorAll('#courier-condition-groups .condition-rule').length > 0;
        var show = currentMode() === 'date' && !isOnce() && typeSel && typeSel.value && !hasRules;
        el.classList.toggle('hidden', !show);
        if (show) {
            var typeLabel = typeSel.options[typeSel.selectedIndex].text.toLowerCase();
            var msg = currentDateSource() === 'fixed' ? L.noRules : L.noConditions;
            el.textContent = msg.replace('{type}', typeLabel);
        }
    }

    // Plain-English readback of the date config, recomposed on every change.
    function updateDateSummary() {
        var el = document.getElementById('courier-date-summary');
        if (!el) return;
        var once = isOnce();
        var typeSel = document.querySelector('select[name="dateElementType"]');
        // A once-send needs no element type; everything else does.
        if (!once && (!typeSel || !typeSel.value)) {
            el.textContent = L.chooseType;
            updateDateWarning();
            return;
        }
        var typeLabel = (typeSel && typeSel.value)
            ? typeSel.options[typeSel.selectedIndex].text.toLowerCase()
            : '';
        var offsetInput = document.querySelector('input[name="dateOffsetValue"]');
        var n = Math.abs(parseInt((offsetInput && offsetInput.value) || '0', 10) || 0);
        var dirSel = document.querySelector('select[name="dateOffsetDirection"]');
        var dir = dirSel ? dirSel.value : 'before';
        var timeInput = document.querySelector('input[name="dateSendTime"]');
        var time = (timeInput && timeInput.value) || '09:00';

        var target;
        var fixed = currentDateSource() === 'fixed';
        if (fixed) {
            var fixedInput = document.querySelector('input[name="fixedDate"]');
            target = (fixedInput && fixedInput.value) || L.placeholderDate;
        } else {
            var fieldSel = document.querySelector('select[name="dateField"]');
            var fieldLabel = (fieldSel && fieldSel.value)
                ? fieldSel.options[fieldSel.selectedIndex].text
                : L.placeholderDate;
            target = L.targetField.replace('{type}', typeLabel).replace('{field}', fieldLabel);
        }

        var tpl;
        if (n === 0) { tpl = L.onDay; }
        else if (dir === 'after') { tpl = n === 1 ? L.dayAfter : L.daysAfter; }
        else { tpl = n === 1 ? L.dayBefore : L.daysBefore; }
        var text = tpl.replace('{n}', n).replace('{target}', target).replace('{time}', time);
        // A fixed date names no audience itself, so spell out who gets it; a
        // field date already reads as "each {type}'s …".
        if (fixed) {
            text += ' ' + (once ? L.onceNote : L.perElement.replace('{type}', typeLabel));
        }
        if (dir === 'before') {
            text += ' ' + L.pastNote;
        }
        el.textContent = text;
        updateDateWarning();
    }

    function fetchBuilder(index) {
        return Craft.sendActionRequest('POST', 'courier/triggers/condition-builder', {
            data: {
                eventTrigger: currentEvent(),
                elementType: currentMode() === 'date' ? currentDateType() : '',
                groupIndex: index
            }
        });
    }

    // The element type changed (different event, different date target, or mode
    // flip) — existing rules are invalid, so reset to one fresh group.
    function resetGroups() {
        if (!container) return;
        container.innerHTML = spinner;
        fetchBuilder(0).then(function(r) {
            container.innerHTML = groupHtml(0, r.data && r.data.html);
            container.setAttribute('data-next-index', '1');
            applyAssets(r);
            Craft.initUiElements($(container));
        }).catch(function() {
            container.innerHTML = '<p class="error">' + {$errorLabel} + '</p>';
        });
    }

    function connectorHtml() {
        return '<div class="courier-cond-connector courier-cond-connector--or"><span class="light">' + {$orLabel} + '</span></div>';
    }

    function groupHtml(index, builderHtml) {
        return '<div class="courier-cond-group" data-group-index="' + index + '">'
            + '<div class="courier-group-head"><button type="button" class="btn small courier-group-remove" data-action="remove-group">' + {$removeLabel} + '</button></div>'
            + '<div class="courier-group-builder">' + (builderHtml || '') + '</div></div>';
    }

    function applyAssets(r) {
        if (r.data && r.data.headHtml) { Craft.appendHeadHtml(r.data.headHtml); }
        if (r.data && r.data.bodyHtml) { Craft.appendBodyHtml(r.data.bodyHtml); }
    }

    // Add an OR group
    var addBtn = document.getElementById('courier-add-group');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            if (!container) return;
            var index = parseInt(container.getAttribute('data-next-index') || '0', 10);
            fetchBuilder(index).then(function(r) {
                if (container.querySelector('.courier-cond-group')) {
                    container.insertAdjacentHTML('beforeend', connectorHtml());
                }
                container.insertAdjacentHTML('beforeend', groupHtml(index, r.data && r.data.html));
                container.setAttribute('data-next-index', index + 1);
                applyAssets(r);
                Craft.initUiElements($(container.lastElementChild));
            });
        });
    }

    // Remove a group (plus an adjacent OR connector)
    if (container) {
        container.addEventListener('click', function(e) {
            var btn = e.target.closest ? e.target.closest('[data-action="remove-group"]') : null;
            if (!btn) return;
            var group = btn.closest('.courier-cond-group');
            if (!group) return;
            var prev = group.previousElementSibling;
            var next = group.nextElementSibling;
            if (prev && prev.classList.contains('courier-cond-connector--or')) {
                prev.remove();
            } else if (next && next.classList.contains('courier-cond-connector--or')) {
                next.remove();
            }
            group.remove();
        });
    }

    // Event change → reset to a single fresh group for the new element type
    $(document).on('change', 'select[name="eventTrigger"]', resetGroups);

    // Date target change → same invalidation, type comes from the date select;
    // the date-field options belong to the type, so they swap too
    $(document).on('change', 'select[name="dateElementType"]', function() {
        resetGroups();
        refreshDateFieldOptions();
    });

    // Any tweak inside the date pane re-composes the summary sentence
    $(document).on('change input', '#courier-mode-date select, #courier-mode-date input', updateDateSummary);

    // Condition rules are added/removed via core's builder (htmx swaps), so
    // watch the DOM to keep the no-conditions warning honest
    if (container && window.MutationObserver) {
        new MutationObserver(updateDateWarning).observe(container, {childList: true, subtree: true});
    }

    // Date source flip → swap the field pair for the date picker, relocate the
    // element-type select, and show/hide the audience row + conditions pane
    $(document).on('change', 'select[name="dateSource"]', function() {
        var fixed = this.value === 'fixed';
        var fieldEl = document.getElementById('courier-date-source-field');
        var fixedEl = document.getElementById('courier-date-source-fixed');
        if (fieldEl) { fieldEl.classList.toggle('hidden', fixed); }
        if (fixedEl) { fixedEl.classList.toggle('hidden', !fixed); }
        placeTypeSelect();
        toggleConditionsBlock();
    });

    // Audience flip → element type + conditions only matter for per-element sends
    $(document).on('change', 'select[name="dateAudience"]', function() {
        placeTypeSelect();
        toggleConditionsBlock();
    });

    // Mode flip → show the right config block and rebuild for its element type
    $(document).on('change', 'select[name="triggerMode"]', function() {
        var isDate = currentMode() === 'date';
        var eventBlock = document.getElementById('courier-mode-event');
        var dateBlock = document.getElementById('courier-mode-date');
        if (eventBlock) { eventBlock.classList.toggle('hidden', isDate); }
        if (dateBlock) { dateBlock.classList.toggle('hidden', !isDate); }
        resetGroups();
        toggleConditionsBlock();
        updateDateSummary();
    });

    placeTypeSelect();
    toggleConditionsBlock();
    updateDateSummary();
})();
JS);

        $group = $heading . $modeField . $eventBlock . $dateBlock . $condMainSlot;

        return Html::tag('div', $group, [
            'class' => ['field', 'courier-field-divider'],
        ]);
    }

    /**
     * Heading + intro for the conditions pane, matching the role it plays:
     * event filter, field-date limiter, or fixed-date audience query. The JS
     * mirror of this branch lives in updateCondCopy().
     *
     * @return array{0:string,1:string}
     */
    private function conditionsCopy(Trigger $element): array
    {
        if ($element->isDateMode() && $element->fixedDate) {
            return [
                Craft::t('courier', 'Audience'),
                Craft::t('courier', 'These rules choose which elements get a send — one apiece, when <strong>any group</strong> matches. Leave blank and every element gets one.'),
            ];
        }
        if ($element->isDateMode()) {
            return [
                Craft::t('courier', 'Conditions'),
                Craft::t('courier', 'Limit which elements get a send as their date approaches — fires when <strong>any group</strong> matches. Leave blank to include them all.'),
            ];
        }
        return [
            Craft::t('courier', 'Conditions'),
            Craft::t('courier', 'Fires when <strong>any group</strong> matches. Within a group, every rule must be true. Leave blank to fire on every event.'),
        ];
    }

    /**
     * The date-mode config, covering three shapes:
     *  - field date  → one send per element as its own date approaches
     *  - fixed date + once     → a single send to the recipients list
     *  - fixed date + elements → one send per element matching the conditions
     * Offset posts as magnitude + direction ("N days or less before" semantics).
     *
     * $conditionsHtml: the conditions block, when its initial home is the slot
     * under "Who gets it?" (fixed dates — where the rules are the audience).
     */
    private function dateConfigHtml(Trigger $element, string $conditionsHtml = ''): string
    {
        // Element types worth offering: everything the event registry knows about.
        $types = [];
        foreach (Courier::$plugin->events->getAll() as $entry) {
            $type = $entry['elementType'] ?? null;
            if ($type && class_exists($type) && !isset($types[$type])) {
                $types[$type] = $type::displayName();
            }
        }
        asort($types);
        $typeOptions = [['label' => Craft::t('courier', 'Choose…'), 'value' => '']];
        foreach ($types as $class => $label) {
            $typeOptions[] = ['label' => $label, 'value' => $class];
        }

        $usingFixed = (bool) $element->fixedDate;
        $usingOnce = $element->isOnceMode();

        // One DOM node, two homes. With a field date the type answers "where
        // does the date live?" (row 1); with a fixed date it answers "what gets
        // a send?" (the audience row). JS relocates it when the source flips.
        $typeSelect = Html::tag('div', Cp::selectHtml([
            'name' => 'dateElementType',
            'value' => $element->dateElementType,
            'options' => $typeOptions,
            'aria' => ['label' => Craft::t('courier', 'Element type')],
        ]), ['id' => 'courier-type-select']);

        // Which date: a select scoped to the chosen element type — its custom
        // Date fields plus its queryable date attributes. Repopulated via the
        // date-field-options endpoint when the element type changes.
        if ($element->dateElementType) {
            $fieldOptions = Courier::$plugin->scheduler->getDateFieldOptions($element->dateElementType);
            array_unshift($fieldOptions, ['label' => Craft::t('courier', 'Choose…'), 'value' => '']);
            // A stored handle the options don't know (deleted field, hand-set
            // value) stays selectable so it round-trips instead of vanishing.
            if ($element->dateField && !in_array($element->dateField, array_column($fieldOptions, 'value'), true)) {
                $fieldOptions[] = ['label' => $element->dateField, 'value' => $element->dateField];
            }
        } else {
            $fieldOptions = [['label' => Craft::t('courier', 'Choose an element type first'), 'value' => '']];
        }
        $dateFieldSelect = Cp::selectHtml([
            'name' => 'dateField',
            'value' => (string) $element->dateField,
            'options' => $fieldOptions,
            'aria' => ['label' => Craft::t('courier', 'Date field')],
        ]);
        $sourceSelect = Cp::selectHtml([
            'name' => 'dateSource',
            'value' => $usingFixed ? 'fixed' : 'field',
            'options' => [
                ['label' => Craft::t('courier', 'A date on each element'), 'value' => 'field'],
                ['label' => Craft::t('courier', 'A specific date'), 'value' => 'fixed'],
            ],
        ]);
        $fixedInput = Html::input('date', 'fixedDate', (string) $element->fixedDate, [
            'class' => 'text',
        ]);

        // Row 1 — The date: source select + its companions on one line. Field
        // source shows [element type][date field]; fixed source shows the picker.
        $row1 = Html::tag('div', Cp::fieldHtml(
            Html::tag('div',
                Html::tag('div', $sourceSelect, ['class' => 'courier-date-source']) .
                Html::tag('div',
                    Html::tag('div', $usingFixed ? '' : $typeSelect, ['id' => 'courier-type-slot-field']) .
                    Html::tag('div', $dateFieldSelect),
                    ['id' => 'courier-date-source-field', 'class' => array_filter(['courier-date-input', 'courier-date-field-pair', $usingFixed ? 'hidden' : null])]
                ) .
                Html::tag('div', $fixedInput, ['id' => 'courier-date-source-fixed', 'class' => array_filter(['courier-date-input', $usingFixed ? null : 'hidden'])]),
                ['class' => 'courier-date-source-row']
            ),
            ['label' => Craft::t('courier', 'The date')]
        ), ['class' => 'courier-date-row']);

        // Row 2 — Who gets it? Fixed dates belong to nothing, so the audience is
        // an explicit choice: a single send to the recipients list, or one send
        // per element matching the conditions. Field dates skip this row — the
        // audience is inherently "each element that owns the date".
        $audienceSelect = Cp::selectHtml([
            'name' => 'dateAudience',
            'value' => $element->dateAudience ?: 'elements',
            'options' => [
                ['label' => Craft::t('courier', 'Just the recipients'), 'value' => 'once'],
                ['label' => Craft::t('courier', 'Each matching'), 'value' => 'elements'],
            ],
        ]);
        $audienceRow = Html::tag('div', Cp::fieldHtml(
            Html::tag('div',
                Html::tag('div', $audienceSelect, ['class' => 'courier-date-source']) .
                Html::tag('div', $usingFixed ? $typeSelect : '', [
                    'id' => 'courier-type-slot-audience',
                    'class' => array_filter(['courier-date-input', $usingOnce ? 'hidden' : null]),
                ]),
                ['class' => 'courier-date-source-row']
            ),
            ['label' => Craft::t('courier', 'Who gets it?')]
        ), ['id' => 'courier-audience-row', 'class' => array_filter(['courier-date-row', $usingFixed ? null : 'hidden'])]);

        // Row 2: when it sends, as one composed phrase. "Before" means "N days or
        // less": elements that enter the window late still send while the date is ahead.
        $offsetInput = Html::input('number', 'dateOffsetValue', (string) abs($element->dateOffsetDays), [
            'class' => 'text',
            'min' => 0,
            'max' => 365,
            'style' => 'width: 5em;',
        ]);
        $directionInput = Cp::selectHtml([
            'name' => 'dateOffsetDirection',
            'value' => $element->dateOffsetDays > 0 ? 'after' : 'before',
            'options' => [
                ['label' => Craft::t('courier', 'days before (or less)'), 'value' => 'before'],
                ['label' => Craft::t('courier', 'days after'), 'value' => 'after'],
            ],
        ]);
        $timeInput = Html::input('time', 'dateSendTime', $element->dateSendTime ?: '09:00', [
            'class' => 'text',
        ]);
        $whenField = Cp::fieldHtml(
            Html::tag('div', $offsetInput . $directionInput . Html::tag('span', Craft::t('courier', 'at'), ['class' => 'light']) . $timeInput, [
                'class' => ['flex', 'flex-nowrap', 'courier-date-when'],
            ]),
            ['label' => Craft::t('courier', 'Send')]
        );

        // Live plain-English readback of the whole configuration (filled by JS),
        // plus a warning when no conditions scope it — the "every entry on the
        // site" footgun made visible.
        $summary = Html::tag('p', '', [
            'id' => 'courier-date-summary',
            'class' => ['light', 'courier-date-note'],
        ]);
        $warning = Html::tag('p', '', [
            'id' => 'courier-date-warning',
            'class' => ['warning', 'with-icon', 'courier-date-warning', 'hidden'],
        ]);

        $condSlot = Html::tag('div', $conditionsHtml, ['id' => 'courier-cond-slot-date']);

        // One contained unit: when → who → which (audience rules) → send time.
        return Html::tag('div', $row1 . $audienceRow . $condSlot . $whenField . $summary . $warning, [
            'class' => ['pane', 'courier-date-pane'],
        ]);
    }

    /**
     * Render one OR group: a namespaced native condition builder wrapped with a
     * remove control, preceded by an "or" connector for every group after the
     * first. The namespace (conditionGroups[N]) is what lets each builder
     * round-trip independently through core's ConditionsController.
     */
    private function groupBlockHtml(CourierSendCondition $group, int $index): string
    {
        $group->mainTag = 'div';
        $group->id = "courier-condition-{$index}";
        $group->name = "conditionGroups[{$index}]";

        $or = $index > 0
            ? Html::tag('div',
                Html::tag('span', Craft::t('courier', 'or'), ['class' => 'light']),
                ['class' => ['courier-cond-connector', 'courier-cond-connector--or']]
            )
            : '';

        $remove = Html::button(Craft::t('courier', 'Remove'), [
            'type' => 'button',
            'class' => ['btn', 'small', 'courier-group-remove'],
            'data' => ['action' => 'remove-group'],
        ]);
        $head = Html::tag('div', $remove, ['class' => 'courier-group-head']);
        $body = Html::tag('div', $group->getBuilderHtml(), ['class' => 'courier-group-builder']);

        return $or . Html::tag('div', $head . $body, [
            'class' => 'courier-cond-group',
            'data' => ['group-index' => $index],
        ]);
    }
}
