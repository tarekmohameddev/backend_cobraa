<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StoxOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'stox_account_id' => $this->stox_account_id,
            'order_id' => $this->order_id,
            'external_order_id' => $this->external_order_id,
            'reference_number' => $this->reference_number,
            'awb_number' => $this->awb_number,
            'export_status' => $this->export_status,
            'retry_count' => $this->retry_count,
            'last_error' => $this->last_error,
            'exported_at' => $this->exported_at,
            'account' => StoxAccountResource::make($this->whenLoaded('account')),
            'order' => $this->whenLoaded('order'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

