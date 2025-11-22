<?php
declare(strict_types=1);

namespace Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:255',
            'phone'         => 'required|string|unique:users,phone',
            'second_phone'  => 'nullable|string',
            'title'         => 'required|string|max:255',
            // For address location, only area_id is strictly required.
            // city_id can be omitted and will be derived from the selected area when missing.
            'city_id'       => 'nullable|integer|exists:cities,id',
            'area_id'       => 'required|integer|exists:areas,id',
            'address'       => 'required|string',
            'note'          => 'nullable|string',
            'location'      => 'nullable|array', // Latitude/Longitude if needed
        ];
    }
}
