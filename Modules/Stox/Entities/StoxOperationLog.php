<?php

declare(strict_types=1);

namespace Modules\Stox\Entities;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoxOperationLog extends Model
{
    use HasFactory;

    protected $table = 'stox_operation_logs';

    protected $fillable = [
        'stox_account_id',
        'stox_order_id',
        'order_id',
        'user_id',
        'operation_type',
        'trigger_type',
        'http_status',
        'execution_time_ms',
        'request_data',
        'response_data',
        'error_message',
        'stack_trace',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'http_status' => 'integer',
        'execution_time_ms' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(StoxAccount::class, 'stox_account_id');
    }

    public function stoxOrder(): BelongsTo
    {
        return $this->belongsTo(StoxOrder::class, 'stox_order_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

