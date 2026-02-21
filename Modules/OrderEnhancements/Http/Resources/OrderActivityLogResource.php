<?php
declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderActivityLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'user_id' => $this->user_id,
            'activity_type' => $this->activity_type,
            'description' => $this->description,
            'lines_added' => (int) ($this->lines_added ?? 0),
            'units_added' => (int) ($this->units_added ?? 0),
            'lines_removed' => (int) ($this->lines_removed ?? 0),
            'units_removed' => (int) ($this->units_removed ?? 0),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s') . 'Z',
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s') . 'Z',
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'firstname' => $this->user->firstname,
                    'lastname' => $this->user->lastname,
                    'email' => $this->user->email,
                ];
            }),
        ];
    }
}

