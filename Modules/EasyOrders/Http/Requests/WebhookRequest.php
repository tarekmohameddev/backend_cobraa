<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WebhookRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			// Only the external order id is required; the rest will be fetched from EasyOrders API
			'id' => ['required', 'string'],
			'store_id' => ['sometimes', 'nullable', 'string'],
			'status' => ['sometimes', 'nullable', 'string'],
			'cart_items' => ['sometimes', 'array'],
		];
	}
}


