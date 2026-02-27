<?php

return [
    'base_currency' => env('ACCOUNTING_BASE_CURRENCY', 'USD'),

    'audit' => [
        'enabled' => true,
        'models' => [],
        'exclude_fields' => ['updated_at', 'created_at', 'deleted_at'],
    ],
];
