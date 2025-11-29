<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
	public function up(): void
	{
		// Extend status enum with waiting_payment and add polling tracking columns.
		DB::statement("
			ALTER TABLE easyorders_temp_orders
			MODIFY COLUMN status ENUM(
				'pending',
				'waiting_payment',
				'validated',
				'failed',
				'approved',
				'imported',
				'import_failed'
			) NOT NULL DEFAULT 'pending'
		");

		Schema::table('easyorders_temp_orders', function (Blueprint $table) {
			$table->timestamp('payment_poll_deadline_at')->nullable()->after('failure_reason');
			$table->unsignedInteger('payment_poll_attempts')->default(0)->after('payment_poll_deadline_at');
		});
	}

	public function down(): void
	{
		Schema::table('easyorders_temp_orders', function (Blueprint $table) {
			$table->dropColumn(['payment_poll_deadline_at', 'payment_poll_attempts']);
		});

		// Revert enum to original definition without waiting_payment.
		DB::statement("
			ALTER TABLE easyorders_temp_orders
			MODIFY COLUMN status ENUM(
				'pending',
				'validated',
				'failed',
				'approved',
				'imported',
				'import_failed'
			) NOT NULL DEFAULT 'pending'
		");
	}
};


