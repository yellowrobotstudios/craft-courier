<?php

namespace yellowrobot\courier\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TextField;
use craft\helpers\Json;

/**
 * A handle text field that live-generates its value from the element's Title
 * using Craft's HandleGenerator (same behaviour as section/field edit screens).
 * Generation stops as soon as the user edits the handle, and never overwrites
 * an existing handle (so existing triggers are safe).
 */
class HandleField extends TextField
{
    public string $attribute = 'handle';

    public function formHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        $html = parent::formHtml($element, $static);

        if (!$static && $html !== null) {
            $target = Json::encode('[name="' . $this->attribute . '"]');
            $source = Json::encode('#title');
            $js = <<<JS
(function () {
    if (typeof Craft === 'undefined' || !Craft.HandleGenerator) {
        return;
    }
    new Craft.HandleGenerator({$source}, {$target});
})();
JS;
            Craft::$app->getView()->registerJs($js);
        }

        return $html;
    }
}
