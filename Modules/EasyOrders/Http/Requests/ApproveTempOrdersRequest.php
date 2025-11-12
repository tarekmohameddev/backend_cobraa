<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveTempOrdersRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'ids' => ['required', 'array'],
			'ids.*' => ['integer'],
		];
	}
}


