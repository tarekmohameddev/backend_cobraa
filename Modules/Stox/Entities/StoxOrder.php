<?php

declare(strict_types=1);

namespace Modules\Stox\Entities;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class StoxOrder extends Model
{
    use HasFactory;

    protected $table = 'stox_orders';

    protected $fillable = [
        'stox_account_id',
        'order_id',
        'external_order_id',
        'awb_number',
        'reference_number',
        'export_status',
        'retry_count',
        'last_error',
        'exported_at',
        'export_payload',
        'response_data',
    ];

    protected $casts = [
        'export_payload' => 'array',
        'response_data' => 'array',
        'exported_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(StoxAccount::class, 'stox_account_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(StoxOperationLog::class);
    }

    public function markExported(?string $externalId, ?string $awbNumber, array $response): void
    {
        $this->forceFill([
            'external_order_id' => $externalId,
            'awb_number' => $awbNumber,
            'export_status' => 'success',
            'response_data' => $response,
            'last_error' => null,
            'exported_at' => Carbon::now(),
        ])->save();
    }
}

