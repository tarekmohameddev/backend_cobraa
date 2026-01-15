<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::create('easyorders_product_syncs', function (Blueprint $table) {
			$table->id();
			$table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
			$table->string('status')->default('pending'); // pending, processing, completed, failed
			$table->integer('start_page')->default(1);
			$table->integer('current_page')->nullable();
			$table->integer('total_pages')->nullable();
			$table->integer('products_synced')->default(0);
			$table->integer('products_failed')->default(0);
			$table->text('error_message')->nullable();
			$table->json('metadata')->nullable(); // Additional info like last_product_id, etc.
			$table->timestamp('started_at')->nullable();
			$table->timestamp('completed_at')->nullable();
			$table->timestamps();

			$table->index(['status', 'created_at']);
			$table->index('user_id');
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('easyorders_product_syncs');
	}
};
