<?php

declare(strict_types=1);

namespace Modules\Stox\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StoxOperationLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'stox_account_id' => $this->stox_account_id,
            'stox_order_id' => $this->stox_order_id,
            'order_id' => $this->order_id,
            'user_id' => $this->user_id,
            'operation_type' => $this->operation_type,
            'trigger_type' => $this->trigger_type,
            'http_status' => $this->http_status,
            'execution_time_ms' => $this->execution_time_ms,
            'request_data' => $this->request_data,
            'response_data' => $this->response_data,
            'error_message' => $this->error_message,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at,
            'account' => StoxAccountResource::make($this->whenLoaded('account')),
            'stox_order' => StoxOrderResource::make($this->whenLoaded('stoxOrder')),
            'user' => $this->whenLoaded('user'),
        ];
    }
}

