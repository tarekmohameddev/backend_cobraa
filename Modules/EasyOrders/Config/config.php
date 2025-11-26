<?php

return [
	'name' => 'EasyOrders',
	'enabled' => env('EASYORDERS_ENABLED', true),

	// Base URL for EasyOrders external apps API, e.g. https://api.easy-orders.net/api/v1
	'base_url' => env('EASYORDERS_BASE_URL', 'https://api.easy-orders.net/api/v1'),

	// Path for fetching full order details: {base_url}/{order_details_path}/{order_id}
	'order_details_path' => env('EASYORDERS_ORDER_DETAILS_PATH', '/external-apps/orders'),

	'ip_allowlist' => env('EASYORDERS_IP_ALLOWLIST', null), // comma-separated list
	'price_policy' => env('EASYORDERS_PRICE_POLICY', 'trust_external'), // trust_external|reprice_from_internal
	'push_status_after_import' => env('EASYORDERS_PUSH_STATUS', false),

	// When true, any temp order that validates successfully will be auto-imported
	'auto_import_validated' => env('EASYORDERS_AUTO_IMPORT_VALIDATED', false),

	// ID of the main shop that will own all EasyOrders-imported products
	'main_shop_id' => (int) env('EASYORDERS_MAIN_SHOP_ID', 1),

	// EasyOrders public API rate limit (requests per minute)
	'rate_limit_per_minute' => (int) env('EASYORDERS_RATE_LIMIT_PER_MINUTE', 40),

	// Optional: slug of a root category under which auto-created EasyOrders
	// categories will be nested. When null, categories are created at top level.
	'root_category_slug' => env('EASYORDERS_ROOT_CATEGORY_SLUG', null),
];
