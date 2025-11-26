<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Product;

class EasyOrdersProduct extends Model
{
	use HasFactory;

	protected $table = 'easyorders_products';

	protected $fillable = [
		'store_id',
		'external_product_id',
		'product_id',
		'external_sku',
		'payload',
		'last_synced_at',
	];

	protected $casts = [
		'payload' => 'array',
		'last_synced_at' => 'datetime',
	];

	public function store(): BelongsTo
	{
		return $this->belongsTo(EasyOrdersStore::class, 'store_id');
	}

	public function product(): BelongsTo
	{
		return $this->belongsTo(Product::class, 'product_id');
	}
}


