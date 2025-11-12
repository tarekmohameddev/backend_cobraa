<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::create('easyorders_temp_orders', function (Blueprint $table) {
			$table->id();
			$table->foreignId('store_id')->constrained('easyorders_stores')->cascadeOnDelete();
			$table->uuid('external_order_id');
			$table->unsignedBigInteger('short_id')->nullable()->index();
			$table->uuid('guest_id')->nullable();

			// status lifecycle
			$table->enum('status', ['pending', 'validated', 'failed', 'approved', 'imported', 'import_failed'])->default('pending')->index();
			$table->text('failure_reason')->nullable();

			// totals
			$table->decimal('cost', 18, 2)->nullable();
			$table->decimal('shipping_cost', 18, 2)->nullable();
			$table->decimal('total_cost', 18, 2)->nullable();
			$table->decimal('expense', 18, 2)->nullable();

			// denormalized for admin UX/search
			$table->string('customer_name')->nullable()->index();
			$table->string('customer_phone', 64)->nullable()->index();
			$table->string('government')->nullable();
			$table->text('address')->nullable();
			$table->string('payment_method', 64)->nullable();
			$table->string('ip', 128)->nullable();
			$table->string('ip_country', 8)->nullable();
			$table->date('created_day')->nullable()->index();

			// raw + normalized payloads
			$table->json('payload')->nullable();
			$table->json('normalized')->nullable();

			// mapping to internal order id after import
			$table->unsignedBigInteger('imported_order_id')->nullable();

			$table->timestamps();

			$table->unique(['store_id', 'external_order_id']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('easyorders_temp_orders');
	}
};


