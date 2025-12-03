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

    public function checkDiscrepancies(Request $request, int $stoxAccount): StreamedResponse
    {
        // Increase memory limit and execution time for large datasets
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '300');

        $account = StoxAccount::findOrFail($stoxAccount);

        $response = new StreamedResponse(function () use ($account) {
            $handle = fopen('php://output', 'w');
            
            // Add BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['SKU', 'Status', 'Product Name (Stox)', 'Product Name (Local)']);

            // Helper to normalize SKU
            $normalizeSku = function ($sku) {
                // Remove non-printable characters, non-breaking spaces, and trim
                // \xC2\xA0 is UTF-8 for non-breaking space
                $sku = preg_replace('/[\x00-\x1F\x7F]|\xC2\xA0/', '', (string)$sku);
                return strtolower(trim($sku));
            };

            // 1. Fetch all Stox SKUs
            $stoxSkus = [];
            $page = 1;
            $hasMore = true;
            $previousPageFirstSku = null;

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

                $responseData = $result['data'];
                $items = $responseData['data'];

                // Check for infinite loop (API ignoring page param)
                // We compare the first item's ID or SKU of this page with the previous page
                $firstItem = $items[0] ?? [];
                $firstIdentifier = $firstItem['id'] ?? $firstItem['sku'] ?? null;

                if ($page > 1 && $firstIdentifier === $previousPageFirstSku) {
                    // We received the exact same first item as the previous page. 
                    // Assume the API is ignoring the ?page= parameter.
                    $hasMore = false;
                    break;
                }
                $previousPageFirstSku = $firstIdentifier;

                foreach ($items as $item) {
                    $originalSku = trim((string) ($item['sku'] ?? ''));
                    if ($originalSku !== '') {
                        $key = $normalizeSku($originalSku);
                        $stoxSkus[$key] = [
                            'original' => $originalSku,
                            'name' => $item['name'] ?? ''
                        ];
                    }
                }

                // Determine if there are more pages
                // Check for 'meta' key or look at root
                $meta = $responseData['meta'] ?? $responseData;
                $currentPage = $meta['current_page'] ?? null;
                $lastPage = $meta['last_page'] ?? null;

                if ($lastPage !== null) {
                    // We have explicit pagination info
                    if ($page >= $lastPage) {
                        $hasMore = false;
                    } else {
                        $page++;
                    }
                    
                    // If the API says current_page is X, but we asked for Y, and X != Y
                    // strict checking here caused issues before. We rely on the loop counter 
                    // and the duplicate check above instead.
                } else {
                    // No explicit last_page info. 
                    // Since we got data (checked above), we assume there might be more.
                    $page++;
                }
            }

            // Track matched Stox SKUs (keys)
            $matchedStoxKeys = [];
            // Track seen local SKUs to avoid duplicates in the report
            $seenLocalKeys = [];

            // 2. Fetch all Local SKUs
            Stock::query()
                ->with('product')
                ->select(['id', 'sku', 'product_id'])
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->chunk(1000, function ($stocks) use ($handle, $stoxSkus, &$matchedStoxKeys, &$seenLocalKeys, $normalizeSku) {
                    foreach ($stocks as $stock) {
                        $localSku = trim((string) $stock->sku);
                        
                        if ($localSku === '') {
                            continue;
                        }

                        $key = $normalizeSku($localSku);

                        if (isset($stoxSkus[$key])) {
                            // Exists in both - mark as matched
                            $matchedStoxKeys[$key] = true;
                        } else {
                            // Exists in Local but not in Stox
                            // Only report if we haven't seen this SKU before
                            if (!isset($seenLocalKeys[$key])) {
                                fputcsv($handle, [
                                    $localSku,
                                    'Local Only',
                                    '',
                                    $stock->product?->translation?->title ?? $stock->product?->uuid ?? 'Unknown Product'
                                ]);
                                $seenLocalKeys[$key] = true;
                            }
                        }
                    }
                });

            // 3. Identify Stox Only SKUs
            foreach ($stoxSkus as $key => $data) {
                if (!isset($matchedStoxKeys[$key])) {
                    fputcsv($handle, [
                        $data['original'],
                        'Stox Only',
                        $data['name'],
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
