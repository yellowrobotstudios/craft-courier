<?php

namespace yellowrobot\courier\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control-panel styles for Courier's trigger edit screen.
 *
 * Carries the semantic CSS classes used by the field-layout UI elements
 * (ConditionsField, VariablesHintField, PreviewField) so their markup stays
 * style-attribute free, matching how Craft core ships CP styling.
 */
class CourierCpAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public $sourcePath = __DIR__ . '/dist';

    /**
     * @inheritdoc
     */
    public $depends = [
        CpAsset::class,
    ];

    /**
     * @inheritdoc
     */
    public $css = [
        'cp.css',
    ];
}
