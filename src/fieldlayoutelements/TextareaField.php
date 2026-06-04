<?php

namespace yellowrobot\courier\fieldlayoutelements;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;

class TextareaField extends BaseNativeField
{
    public int $rows = 8;
    public bool $code = false;
    public ?string $name = null;

    protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        $class = ['text', 'fullwidth'];
        $style = '';
        if ($this->code) {
            $class[] = 'code';
            $style = 'font-family:monospace; font-size:13px; tab-size:2; white-space:pre; overflow-x:auto;';
        }

        return Craft::$app->getView()->renderTemplate('_includes/forms/textarea.twig', [
            'id' => $this->id(),
            'describedBy' => $this->describedBy($element, $static),
            'name' => $this->name ?? $this->attribute(),
            'value' => $this->value($element),
            'class' => $class,
            'rows' => $this->rows,
            'disabled' => $static,
            'required' => !$static && $this->required,
            'inputAttributes' => $this->code ? ['style' => $style] : [],
        ]);
    }

    protected function baseInputName(): string
    {
        return $this->name ?? parent::baseInputName();
    }
}
