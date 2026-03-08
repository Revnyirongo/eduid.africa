<?php

$container = require __DIR__ . '/../bootstrap_symfony.php';
$sspgetter = $container->get(\App\Utils\SSPGetter::class);

$metadata = $sspgetter->getIdps($_SERVER['HTTP_HOST'] ?? '');

foreach ($metadata as $entityId => &$entry) {
    if (!is_array($entry)) {
        continue;
    }

    if (isset($entry['SingleSignOnServiceBinding'], $entry['SingleSignOnService']) && is_string($entry['SingleSignOnService'])) {
        $ssoBindings = (array) $entry['SingleSignOnServiceBinding'];
        $ssoLocation = $entry['SingleSignOnService'];
        $entry['SingleSignOnService'] = [];
        foreach ($ssoBindings as $binding) {
            $entry['SingleSignOnService'][] = [
                'Binding' => $binding,
                'Location' => $ssoLocation,
            ];
        }
        unset($entry['SingleSignOnServiceBinding']);
    }

    if (isset($entry['SingleLogoutServiceBinding'], $entry['SingleLogoutService']) && is_string($entry['SingleLogoutService'])) {
        $sloBindings = (array) $entry['SingleLogoutServiceBinding'];
        $sloLocation = $entry['SingleLogoutService'];
        $entry['SingleLogoutService'] = [];
        foreach ($sloBindings as $binding) {
            $entry['SingleLogoutService'][] = [
                'Binding' => $binding,
                'Location' => $sloLocation,
            ];
        }
        unset($entry['SingleLogoutServiceBinding']);
    }
}
unset($entry);

return $metadata;
