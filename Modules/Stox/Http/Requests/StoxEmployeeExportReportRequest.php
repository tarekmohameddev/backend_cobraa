<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoxEmployeeExportReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
