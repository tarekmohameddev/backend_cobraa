<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Requests;

class StoxAccountUpdateRequest extends StoxAccountRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['bearer_token'] = ['nullable', 'string'];

        return $rules;
    }
}

