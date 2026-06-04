<?php

namespace yellowrobot\courier\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use yellowrobot\courier\Courier;

/**
 * Event picker for a Trigger — a grouped <select> (optgroups by category)
 * populated from the EventRegistry. Bound to the `eventTrigger` attribute.
 */
class EventSelectField extends BaseNativeField
{
    public string $attribute = 'eventTrigger';

    protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        $options = [['label' => Craft::t('courier', 'Select an event…'), 'value' => '']];
        foreach (Courier::$plugin->events->getGroupedOptions() as $opt) {
            $options[] = $opt;
        }

        // Selectize = Craft's built-in searchable/filterable dropdown (supports optgroups)
        return Craft::$app->getView()->renderTemplate('_includes/forms/selectize.twig', [
            'id' => $this->id(),
            'describedBy' => $this->describedBy($element, $static),
            'name' => $this->attribute(),
            'value' => $this->value($element),
            'options' => $options,
            'disabled' => $static,
        ]);
    }
}
