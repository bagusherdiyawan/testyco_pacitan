<?php

return [
    // Operational UI visibility flags: hide heavy/enterprise modules from the sidebar by default
    // without removing backend routes, API endpoints, or historical data.
    'show_assets' => env('UI_SHOW_ASSETS', false),
    'show_commercial' => env('UI_SHOW_COMMERCIAL', false),
    'show_advanced_accounting' => env('UI_SHOW_ADVANCED_ACCOUNTING', false),
    'show_coordinated_recalls' => env('UI_SHOW_COORDINATED_RECALLS', false),
    'show_manufacturing' => env('UI_SHOW_MANUFACTURING', true),
    'show_order_changes_legacy' => env('UI_SHOW_ORDER_CHANGES_LEGACY', false),
];
