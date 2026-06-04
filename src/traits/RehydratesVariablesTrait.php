<?php

namespace yellowrobot\courier\traits;

use Craft;

trait RehydratesVariablesTrait
{
    private function _rehydrateVariables(array $variables): array
    {
        $result = [];
        foreach ($variables as $key => $value) {
            if (is_array($value) && isset($value['__elementType'], $value['__elementId'])) {
                $element = Craft::$app->get('elements')->getElementById(
                    $value['__elementId'],
                    $value['__elementType'],
                );

                if ($element) {
                    $result[$key] = $element;
                } else {
                    Craft::warning("Could not rehydrate element '{$key}' (type: {$value['__elementType']}, id: {$value['__elementId']})", __METHOD__);
                }
            } elseif (is_array($value)) {
                $result[$key] = $this->_rehydrateVariables($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
