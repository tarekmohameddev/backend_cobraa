<?php
declare(strict_types=1);

namespace Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'discount' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ];
    }
}
