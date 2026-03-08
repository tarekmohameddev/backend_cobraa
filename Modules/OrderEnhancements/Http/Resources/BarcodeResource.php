<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Resources;

use App\Models\Currency;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BarcodeResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array
     */
    public function toArray($request): array
    {
        /** @var Stock|JsonResource $this */
        $variantLabel = $this->stockExtras
            ->map(fn ($extra) => $extra->value?->value)
            ->filter()
            ->implode(' / ');

        $currencySymbol = Currency::currenciesList()->where('default', 1)->first()?->symbol;

        return [
            'stock_id'        => $this->id,
            'sku'             => $this->sku,
            'product_name'    => $this->product?->translation?->title,
            'variant_label'   => $variantLabel ?: null,
            'price'           => $this->price,
            'currency_symbol' => $currencySymbol,
        ];
    }
}
