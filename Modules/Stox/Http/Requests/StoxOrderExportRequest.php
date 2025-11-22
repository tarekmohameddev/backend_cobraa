<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoxOrderExportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'stox_account_id' => ['required', 'exists:stox_accounts,id'],
            'override_data' => ['nullable', 'array'],
            'override_data.customer_name' => ['nullable', 'string', 'max:255'],
            'override_data.address' => ['nullable', 'string'],
            'override_data.mobile_1' => ['nullable', 'string', 'max:30'],
            'override_data.mobile_2' => ['nullable', 'string', 'max:30'],
            'override_data.area_id' => ['nullable', 'integer'],
            'override_data.area_name' => ['nullable', 'string', 'max:255'],
            'override_data.email' => ['nullable', 'email'],
            'override_data.reference_number' => ['nullable', 'string', 'max:255'],
            'override_data.payment_type_id' => ['nullable', 'in:COD,CC'],
            'override_data.cod_amount' => ['nullable', 'numeric', 'min:0'],
            'override_data.can_open' => ['nullable', 'boolean'],
            'override_data.note' => ['nullable', 'string'],
            'override_data.products' => ['nullable', 'array'],
            'override_data.products.*.sku' => ['required_with:override_data.products', 'string'],
            'override_data.products.*.qty' => ['required_with:override_data.products', 'integer', 'min:1'],
            'override_data.products.*.item_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}

