<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LookupStockBySkuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku'     => ['required', 'string', 'max:255'],
            'shop_id' => ['nullable', 'integer', 'exists:shops,id'],
        ];
    }
}
