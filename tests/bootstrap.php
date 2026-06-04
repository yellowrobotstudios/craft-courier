<?php

// Load Yii before Composer autoloader to avoid redeclaration issues
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

// Load Craft class (extends Yii) so Craft:: static calls work in tests
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';

require dirname(__DIR__) . '/vendor/autoload.php';

// Bootstrap a minimal Yii application so model validation and
// Craft static method calls work without the full Craft environment
new \yii\console\Application([
    'id' => 'courier-test',
    'basePath' => dirname(__DIR__),
    'components' => [
        // Catch-all message source so Craft::t() falls through to the original
        // string instead of throwing for unregistered categories.
        'i18n' => [
            'translations' => [
                '*' => ['class' => \yii\i18n\PhpMessageSource::class],
            ],
        ],
    ],
]);
