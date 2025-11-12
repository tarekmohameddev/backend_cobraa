<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EasyOrdersStoreResource extends JsonResource
{
	public function toArray($request): array
	{
		return [
			'id' => $this->id,
			'name' => $this->name,
			'external_store_id' => $this->external_store_id,
			'status' => $this->status,
			'has_api_key' => (bool) $this->api_key,
			'webhook_secret' => $this->when($request->user(), $this->webhook_secret),
			'created_at' => optional($this->created_at)->toISOString(),
		];
	}
}


