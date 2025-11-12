<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EasyOrdersWebhookLog extends Model
{
	use HasFactory;

	protected $table = 'easyorders_webhook_logs';

	protected $fillable = [
		'store_id',
		'request_headers',
		'request_body',
		'http_status',
		'error',
	];

	protected $casts = [
		'request_headers' => 'array',
		'request_body' => 'array',
	];

	public function store(): BelongsTo
	{
		return $this->belongsTo(EasyOrdersStore::class, 'store_id');
	}
}


