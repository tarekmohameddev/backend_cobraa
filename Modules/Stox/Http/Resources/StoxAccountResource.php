<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StoxAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'base_url' => $this->base_url,
            'auto_export_statuses' => $this->auto_export_statuses,
            'export_delay_minutes' => $this->export_delay_minutes,
            'settings' => $this->settings,
            'default_payment_mapping' => $this->default_payment_mapping,
            'webhook_signature' => $this->webhook_signature,
            'orders_count' => $this->when(isset($this->orders_count), $this->orders_count),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

