<?php

declare(strict_types=1);

namespace Modules\OrderEnhancements\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderUpdateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'user_id' => $this->user_id,
            'update_type' => $this->update_type,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'is_internal' => $this->is_internal,
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
