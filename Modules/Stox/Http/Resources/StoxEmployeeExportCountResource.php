<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StoxEmployeeExportCountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'user_id' => $this->resource->user_id,
            'user_name' => $this->resource->user_name,
            'orders_exported_count' => $this->resource->orders_exported_count,
        ];
    }
}
