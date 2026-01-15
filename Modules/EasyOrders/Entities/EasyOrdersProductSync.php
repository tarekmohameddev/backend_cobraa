<?php

declare(strict_types=1);

namespace Modules\EasyOrders\Entities;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EasyOrdersProductSync extends Model
{
	use HasFactory;

	protected $table = 'easyorders_product_syncs';

	public const STATUS_PENDING = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED = 'failed';

	public const STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_PROCESSING,
		self::STATUS_COMPLETED,
		self::STATUS_FAILED,
	];

	protected $fillable = [
		'user_id',
		'status',
		'start_page',
		'current_page',
		'total_pages',
		'products_synced',
		'products_failed',
		'error_message',
		'metadata',
		'started_at',
		'completed_at',
	];

	protected $casts = [
		'metadata' => 'array',
		'started_at' => 'datetime',
		'completed_at' => 'datetime',
	];

	public function user(): BelongsTo
	{
		return $this->belongsTo(User::class);
	}

	/**
	 * Mark sync as started
	 */
	public function markAsStarted(): void
	{
		$this->update([
			'status' => self::STATUS_PROCESSING,
			'started_at' => now(),
		]);
	}

	/**
	 * Mark sync as completed
	 */
	public function markAsCompleted(): void
	{
		$this->update([
			'status' => self::STATUS_COMPLETED,
			'completed_at' => now(),
		]);
	}

	/**
	 * Mark sync as failed
	 */
	public function markAsFailed(string $errorMessage): void
	{
		$this->update([
			'status' => self::STATUS_FAILED,
			'error_message' => $errorMessage,
			'completed_at' => now(),
		]);
	}

	/**
	 * Update progress
	 */
	public function updateProgress(int $currentPage, ?int $totalPages = null, int $productsSynced = 0, int $productsFailed = 0): void
	{
		$this->update([
			'current_page' => $currentPage,
			'total_pages' => $totalPages ?? $this->total_pages,
			'products_synced' => $productsSynced,
			'products_failed' => $productsFailed,
		]);
	}

	/**
	 * Get the latest active sync for a user
	 */
	public static function getLatestActive(?int $userId = null): ?self
	{
		$query = self::query()
			->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
			->orderByDesc('created_at');

		if ($userId) {
			$query->where('user_id', $userId);
		}

		return $query->first();
	}
}
