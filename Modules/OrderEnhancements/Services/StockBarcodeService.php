<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Services;

use App\Models\Currency;
use App\Models\Language;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Collection;

class StockBarcodeService
{
    public function findBySku(string $sku, ?int $shopId = null): ?Stock
    {
        $locale = Language::where('default', 1)->first()?->locale;

        return Stock::with([
            'stockExtras.value',
            'stockExtras.group.translation' => fn ($q) => $q->where(function ($q) use ($locale) {
                $q->where('locale', $locale);
            }),
            'galleries',
            'product' => fn ($q) => $q->select([
                'id', 'uuid', 'shop_id', 'unit_id', 'img', 'min_qty', 'max_qty', 'interval', 'tax',
            ]),
            'product.translation' => fn ($q) => $q
                ->select(['id', 'product_id', 'locale', 'title'])
                ->where('locale', $locale),
            'product.unit.translation' => fn ($q) => $q
                ->select(['id', 'unit_id', 'locale', 'title'])
                ->where('locale', $locale),
        ])
            ->whereHas('product', fn ($q) => $q
                ->where('status', 'published')
                ->where('active', 1)
                ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            )
            ->where('sku', $sku)
            ->first();
    }

    public function getBarcodesForProducts(array $productIds, ?int $shopId = null): Collection
    {
        $locale = Language::where('default', 1)->first()?->locale;

        return Stock::with([
            'stockExtras.value',
            'product' => fn ($q) => $q->select(['id', 'shop_id', 'tax', 'interval']),
            'product.translation' => fn ($q) => $q
                ->select(['id', 'product_id', 'locale', 'title'])
                ->where('locale', $locale),
        ])
            ->whereHas('product', fn ($q) => $q
                ->whereIn('id', $productIds)
                ->when($shopId, fn ($q) => $q->where('shop_id', $shopId))
            )
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->get();
    }

    public function getCurrencySymbol(): ?string
    {
        return Currency::currenciesList()->where('default', 1)->first()?->symbol;
    }
}
