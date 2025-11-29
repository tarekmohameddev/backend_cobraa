<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EasyOrdersTempOrder extends Model
{
	use HasFactory;

	protected $table = 'easyorders_temp_orders';

	protected $fillable = [
		'store_id',
		'external_order_id',
		'short_id',
		'guest_id',
		'status',
		'failure_reason',
		'cost',
		'shipping_cost',
		'total_cost',
		'expense',
		'customer_name',
		'customer_phone',
		'government',
		'address',
		'payment_method',
		'ip',
		'ip_country',
		'created_day',
		'payload',
		'normalized',
		'imported_order_id',
		'payment_poll_deadline_at',
		'payment_poll_attempts',
	];

	protected $casts = [
		'payload' => 'array',
		'normalized' => 'array',
		'created_day' => 'date',
		'cost' => 'decimal:2',
		'shipping_cost' => 'decimal:2',
		'total_cost' => 'decimal:2',
		'expense' => 'decimal:2',
		'payment_poll_deadline_at' => 'datetime',
		'payment_poll_attempts' => 'integer',
	];

	public function store(): BelongsTo
	{
		return $this->belongsTo(EasyOrdersStore::class, 'store_id');
	}
}


