<?php

namespace yellowrobot\courier\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use yellowrobot\courier\Courier;
use yellowrobot\courier\elements\Trigger;

/**
 * Multi-select of configured channels (ChannelConfig instances) for a trigger.
 * Bound to the `channelIds` attribute (an array of channel uids).
 */
class ChannelsField extends BaseNativeField
{
    public string $attribute = 'channelIds';

    protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        $options = Courier::$plugin->channels->getConfigOptions();

        if (empty($options)) {
            // Compose the link with Html::a so the URL is attribute-encoded,
            // rather than interpolating it into a raw HTML string.
            $link = Html::a(
                Craft::t('courier', 'Add one'),
                UrlHelper::cpUrl('courier/channels/new'),
            );
            return Html::tag('p', Craft::t(
                'courier',
                'No channels configured yet. {link} to send notifications.',
                ['link' => $link],
            ), ['class' => 'light']);
        }

        $values = $element instanceof Trigger ? $element->getChannelUids() : [];

        return Craft::$app->getView()->renderTemplate('_includes/forms/checkboxSelect.twig', [
            'id' => $this->id(),
            'name' => $this->attribute(),
            'options' => $options,
            'values' => $values,
            'describedBy' => $this->describedBy($element, $static),
            'disabled' => $static,
        ]);
    }
}
