<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockBarcodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_ids'   => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'shop_id'       => ['nullable', 'integer', 'exists:shops,id'],
        ];
    }
}
