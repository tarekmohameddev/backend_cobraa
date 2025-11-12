<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EasyOrdersStore extends Model
{
	use HasFactory;

	protected $table = 'easyorders_stores';

	protected $fillable = [
		'name',
		'external_store_id',
		'status',
		'api_key',
		'webhook_secret',
		'settings',
		'last_sync_at',
	];

	protected $casts = [
		'settings' => 'array',
		'last_sync_at' => 'datetime',
	];

	public function tempOrders(): HasMany
	{
		return $this->hasMany(EasyOrdersTempOrder::class, 'store_id');
	}
}


