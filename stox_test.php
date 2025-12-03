<?php

use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Services\StoxApiService;
use Modules\Stox\Http\Controllers\Dashboard\Admin\StoxProductSyncController;
use Illuminate\Http\Request;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Mock StoxApiService
$mockService = new class extends StoxApiService {
    public function __construct() {}
    
    public function fetchProducts(StoxAccount $account, int $page = 1): array
    {
        if ($page > 1) {
             return [
                'success' => true,
                'data' => [
                    'data' => [],
                    'meta' => ['current_page' => $page, 'last_page' => 1]
                ]
            ];
        }
        
        return [
            'success' => true,
            'data' => [
                'data' => [
                    ['sku' => 'STOX-001', 'name' => 'Stox Product 1'],
                    ['sku' => 'LOCAL-001', 'name' => 'Shared Product'],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1]
            ]
        ];
    }
};

// Create a dummy StoxAccount if not exists
if (StoxAccount::count() === 0) {
    StoxAccount::create([
        'base_url' => 'https://example.com',
        'username' => 'test',
        'password' => 'test',
        'bearer_token' => 'test',
        'expires_at' => now()->addDay(),
    ]);
}

// Create dummy local stocks
$product = \App\Models\Product::first();
if (!$product) {
    $product = \App\Models\Product::factory()->create();
}

// Clear existing stocks for test
\App\Models\Stock::where('sku', 'like', 'LOCAL%')->delete();

\App\Models\Stock::create(['product_id' => $product->id, 'sku' => 'LOCAL-001', 'price' => 100, 'quantity' => 10]);
\App\Models\Stock::create(['product_id' => $product->id, 'sku' => 'LOCAL-ONLY-001', 'price' => 100, 'quantity' => 10]);

$controller = new StoxProductSyncController($mockService);

try {
    $account = StoxAccount::first();
    echo "Testing with Account ID: " . $account->id . "\n";
    
    $response = $controller->checkDiscrepancies(new Request(), $account);
    
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();
    
    echo "CSV Content Generated:\n";
    file_put_contents('stox_test_output.csv', $content);
    echo "Output written to stox_test_output.csv";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
    echo "\n" . $e->getTraceAsString();
}
