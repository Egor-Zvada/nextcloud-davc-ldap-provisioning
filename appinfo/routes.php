<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'name' => 'settings#save',
            'url' => '/settings',
            'verb' => 'POST',
        ],
        [
            'name' => 'settings#principals',
            'url' => '/settings/principals',
            'verb' => 'GET',
        ],
        [
            'name' => 'settings#syncProfile',
            'url' => '/settings/profiles/{profileId}/sync',
            'verb' => 'POST',
        ],
    ],
];
