<?php

namespace yellowrobot\courier\traits;

use Craft;
use craft\helpers\App;

/**
 * Shared recipient-list resolution, used by both the live send job and the
 * CP test-send so they behave identically. Twig is evaluated first (per-event,
 * e.g. `{{ object.email }}`), then each comma-split token is run through env-var
 * resolution so static addresses like `$EMAIL_TO` work too — even mixed, and an
 * env var that itself holds a comma list is expanded.
 */
trait ResolvesRecipientsTrait
{
    /**
     * @param array<string,mixed> $variables
     * @return string[]
     */
    protected function renderRecipientList(?string $expr, array $variables): array
    {
        if (!$expr) {
            return [];
        }
        try {
            $out = trim(Craft::$app->getView()->renderString($expr, $variables));
        } catch (\Throwable $e) {
            Craft::error("Courier recipient expression failed: {$e->getMessage()}", __METHOD__);
            return [];
        }
        if ($out === '') {
            return [];
        }

        $addresses = [];
        foreach (explode(',', $out) as $token) {
            $resolved = (string) App::parseEnv(trim($token));
            foreach (explode(',', $resolved) as $addr) {
                $addr = trim($addr);
                if ($addr !== '') {
                    $addresses[] = $addr;
                }
            }
        }
        return array_values(array_unique($addresses));
    }
}
