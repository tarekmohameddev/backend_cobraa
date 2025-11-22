<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoxAccountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],
            'base_url' => ['nullable', 'url'],
            'bearer_token' => ['required', 'string'],
            'webhook_signature' => ['nullable', 'string', 'max:255'],
            'auto_export_statuses' => ['nullable', 'array'],
            'auto_export_statuses.*' => ['string', 'max:50'],
            'default_payment_mapping' => ['nullable', 'array'],
            'export_delay_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'settings' => ['nullable', 'array'],
            'settings.shop_ids' => ['nullable', 'array'],
            'settings.shop_ids.*' => ['integer'],
            'settings.payment_types' => ['nullable', 'array'],
            'settings.payment_types.*' => ['string', 'max:50'],
            'settings.mobile_2' => ['nullable', 'string', 'max:30'],
            'settings.can_open' => ['nullable', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}

