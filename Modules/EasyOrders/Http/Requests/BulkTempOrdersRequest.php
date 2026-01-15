<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkTempOrdersRequest extends FormRequest
{
	public function authorize(): bool
	{
		return true;
	}

	public function rules(): array
	{
		return [
			'action' => ['required', 'string', Rule::in(['approve_and_import', 'validate', 'import', 'delete'])],
			'ids' => ['required', 'array', 'min:1'],
			'ids.*' => ['integer'],
			'force' => ['sometimes', 'boolean'],
		];
	}
}

