<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	/**
	 * Run the migrations.
	 */
	public function up(): void
	{
		Schema::create('easyorders_stores', function (Blueprint $table) {
			$table->id();
			$table->string('name');
			$table->uuid('external_store_id')->nullable()->unique();
			$table->string('status')->default('active')->index();
			$table->string('api_key')->nullable();
			$table->string('webhook_secret');
			$table->json('settings')->nullable();
			$table->timestamp('last_sync_at')->nullable();
			$table->timestamps();
		});
	}

	/**
	 * Reverse the migrations.
	 */
	public function down(): void
	{
		Schema::dropIfExists('easyorders_stores');
	}
};


