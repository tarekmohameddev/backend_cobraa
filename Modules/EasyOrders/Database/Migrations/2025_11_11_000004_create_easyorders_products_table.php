<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::create('easyorders_products', function (Blueprint $table) {
			$table->id();
			$table->foreignId('store_id')->nullable()->constrained('easyorders_stores')->nullOnDelete();
			$table->uuid('external_product_id');
			$table->unsignedBigInteger('product_id');
			$table->string('external_sku')->nullable();
			$table->json('payload')->nullable();
			$table->timestamp('last_synced_at')->nullable();
			$table->timestamps();

			$table->unique(['store_id', 'external_product_id']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('easyorders_products');
	}
};


