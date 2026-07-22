<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use App\Http\Requests\BaseRequest;

class OrderPhoneAltUpdateRequest extends BaseRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'phone_alt' => 'nullable|string|max:64',
        ];
    }
}
