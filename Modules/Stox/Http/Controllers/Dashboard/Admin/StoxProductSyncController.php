<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Stox\Entities\StoxAccount;
use Modules\Stox\Services\StoxApiService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoxProductSyncController extends Controller
{
    public function __construct(private readonly StoxApiService $stoxApiService)
    {
    }

    public function checkDiscrepancies(Request $request): StreamedResponse
    {
        // Increase memory limit and execution time for large datasets
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '300');

        $account = StoxAccount::firstOrFail();

        $response = new StreamedResponse(function () use ($account) {
            $handle = fopen('php://output', 'w');
            
            // Add BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['SKU', 'Status', 'Product Name (Stox)', 'Product Name (Local)']);

            // 1. Fetch all Stox SKUs
            $stoxSkus = [];
            $page = 1;
            $hasMore = true;

            while ($hasMore) {
                // Safety break
                if ($page > 1000) {
                    break;
                }

                $result = $this->stoxApiService->fetchProducts($account, $page);

                if (!$result['success'] || empty($result['data']['data'])) {
                    $hasMore = false;
                    break;
                }

                $meta = $result['data']['meta'] ?? [];
                $currentPage = $meta['current_page'] ?? 0;
                $lastPage = $meta['last_page'] ?? 0;

                // Verify we got the page we asked for
                if ($currentPage != $page) {
                    // API ignored our page parameter or returned wrong page
                    $hasMore = false;
                    break;
                }

                foreach ($result['data']['data'] as $item) {
                    $sku = trim((string) ($item['sku'] ?? ''));
                    if ($sku !== '') {
                        $stoxSkus[$sku] = $item['name'] ?? '';
                    }
                }

                if ($currentPage >= $lastPage) {
                    $hasMore = false;
                } else {
                    $page++;
                }
            }

            // Track matched Stox SKUs to identify "Stox Only" later
            $matchedStoxSkus = [];

            // 2. Fetch all Local SKUs
            Stock::query()
                ->with('product')
                ->select(['id', 'sku', 'product_id'])
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->chunk(1000, function ($stocks) use ($handle, $stoxSkus, &$matchedStoxSkus) {
                    foreach ($stocks as $stock) {
                        $localSku = trim((string) $stock->sku);
                        
                        if ($localSku === '') {
                            continue;
                        }

                        if (isset($stoxSkus[$localSku])) {
                            // Exists in both - mark as matched
                            $matchedStoxSkus[$localSku] = true;
                        } else {
                            // Exists in Local but not in Stox
                            fputcsv($handle, [
                                $localSku,
                                'Local Only',
                                '',
                                $stock->product?->translation?->title ?? $stock->product?->uuid ?? 'Unknown Product'
                            ]);
                        }
                    }
                });

            // 3. Identify Stox Only SKUs
            foreach ($stoxSkus as $sku => $name) {
                if (!isset($matchedStoxSkus[$sku])) {
                    fputcsv($handle, [
                        $sku,
                        'Stox Only',
                        $name,
                        ''
                    ]);
                }
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="stox_sku_discrepancies.csv"');

        return $response;
    }
}
