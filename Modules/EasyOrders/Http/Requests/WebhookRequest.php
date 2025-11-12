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
			'id' => ['required', 'string'],
			'store_id' => ['required', 'string'],
			'status' => ['required', 'string'],
			'cart_items' => ['required', 'array'],
		];
	}
}


