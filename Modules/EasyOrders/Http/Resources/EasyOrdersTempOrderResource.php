<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EasyOrdersTempOrderResource extends JsonResource
{
	public function toArray($request): array
	{
		return [
			'id' => $this->id,
			'store_id' => $this->store_id,
			'external_order_id' => $this->external_order_id,
			'short_id' => $this->short_id,
			'status' => $this->status,
			'failure_reason' => $this->failure_reason,
			'customer_name' => $this->customer_name,
			'customer_phone' => $this->customer_phone,
			'government' => $this->government,
			'address' => $this->address,
			'payment_method' => $this->payment_method,
			'cost' => $this->cost,
			'shipping_cost' => $this->shipping_cost,
			'total_cost' => $this->total_cost,
			'created_day' => optional($this->created_day)->toDateString(),
			'payload' => $this->when($request->user(), $this->payload),
			'normalized' => $this->normalized,
			'created_at' => optional($this->created_at)->toISOString(),
		];
	}
}


