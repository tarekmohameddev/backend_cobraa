<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::table('easyorders_webhook_logs', function (Blueprint $table) {
			if (!Schema::hasColumn('easyorders_webhook_logs', 'external_order_id')) {
				$table->uuid('external_order_id')->nullable()->after('store_id')->index();
			}

			if (!Schema::hasColumn('easyorders_webhook_logs', 'processing_status')) {
				$table->enum('processing_status', ['received', 'processing', 'processed', 'failed'])
					->default('received')
					->after('external_order_id')
					->index();
			}

			if (!Schema::hasColumn('easyorders_webhook_logs', 'processed_at')) {
				$table->timestamp('processed_at')->nullable()->after('processing_status');
			}

			if (!Schema::hasColumn('easyorders_webhook_logs', 'attempts')) {
				$table->unsignedInteger('attempts')->default(0)->after('processed_at');
			}

			$table->index(['processing_status', 'created_at'], 'easyorders_webhook_logs_status_created_at_index');
			$table->unique(['store_id', 'external_order_id'], 'easyorders_webhook_logs_store_external_unique');
		});
	}

	public function down(): void
	{
		Schema::table('easyorders_webhook_logs', function (Blueprint $table) {
			$table->dropUnique('easyorders_webhook_logs_store_external_unique');
			$table->dropIndex('easyorders_webhook_logs_status_created_at_index');

			if (Schema::hasColumn('easyorders_webhook_logs', 'attempts')) {
				$table->dropColumn('attempts');
			}
			if (Schema::hasColumn('easyorders_webhook_logs', 'processed_at')) {
				$table->dropColumn('processed_at');
			}
			if (Schema::hasColumn('easyorders_webhook_logs', 'processing_status')) {
				$table->dropColumn('processing_status');
			}
			if (Schema::hasColumn('easyorders_webhook_logs', 'external_order_id')) {
				$table->dropColumn('external_order_id');
			}
		});
	}
};

