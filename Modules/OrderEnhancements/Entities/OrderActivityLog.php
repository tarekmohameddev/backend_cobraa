<?php
declare(strict_types=1);

namespace Modules\OrderEnhancements\Entities;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderActivityLog extends Model
{
    use HasFactory;

    protected $table = 'order_activity_logs';

    protected $fillable = [
        'order_id',
        'user_id',
        'activity_type',
        'description',
        'lines_added',
        'units_added',
        'lines_removed',
        'units_removed',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'lines_added' => 'integer',
        'units_added' => 'integer',
        'lines_removed' => 'integer',
        'units_removed' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFilter(Builder $query, array $filter): Builder
    {
        return $query
            ->when(data_get($filter, 'order_id'), fn($q, $orderId) => $q->where('order_id', $orderId))
            ->when(data_get($filter, 'user_id'), fn($q, $userId) => $q->where('user_id', $userId))
            ->when(data_get($filter, 'activity_type'), fn($q, $type) => $q->where('activity_type', $type))
            ->when(data_get($filter, 'from_date'), fn($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when(data_get($filter, 'to_date'), fn($q, $to) => $q->whereDate('created_at', '<=', $to));
    }
}

