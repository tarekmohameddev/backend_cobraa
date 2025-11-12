<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'name' => ['required', 'string', 'max:255'],
			'external_store_id' => ['nullable', 'string', 'max:255'],
			'status' => ['nullable', 'in:active,inactive'],
			'api_key' => ['nullable', 'string'],
			'webhook_secret' => ['nullable', 'string'],
		];
	}
}


