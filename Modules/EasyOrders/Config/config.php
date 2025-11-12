<?php

return [
	'name' => 'EasyOrders',
	'enabled' => env('EASYORDERS_ENABLED', true),
	'base_url' => env('EASYORDERS_BASE_URL', 'https://public-api.easy-orders.net'),
	'ip_allowlist' => env('EASYORDERS_IP_ALLOWLIST', null), // comma-separated list
	'price_policy' => env('EASYORDERS_PRICE_POLICY', 'trust_external'), // trust_external|reprice_from_internal
	'push_status_after_import' => env('EASYORDERS_PUSH_STATUS', false),
];
