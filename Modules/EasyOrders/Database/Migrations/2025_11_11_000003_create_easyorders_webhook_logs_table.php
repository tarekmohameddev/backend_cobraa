<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
	public function up(): void
	{
		Schema::create('easyorders_webhook_logs', function (Blueprint $table) {
			$table->id();
			$table->foreignId('store_id')->nullable()->constrained('easyorders_stores')->nullOnDelete();
			$table->json('request_headers')->nullable();
			$table->json('request_body')->nullable();
			$table->integer('http_status')->nullable();
			$table->text('error')->nullable();
			$table->timestamps();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('easyorders_webhook_logs');
	}
};


