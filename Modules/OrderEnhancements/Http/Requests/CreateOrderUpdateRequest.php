<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:5000'],
            'update_type' => ['nullable', 'string', 'in:note,escalation,internal'],
            'metadata' => ['nullable', 'array'],
            'is_internal' => ['nullable', 'boolean'],
        ];
    }
}
