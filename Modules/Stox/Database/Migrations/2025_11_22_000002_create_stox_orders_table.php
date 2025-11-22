<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stox_orders', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stox_account_id')
                ->constrained('stox_accounts')
                ->cascadeOnDelete();
            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('external_order_id')->nullable();
            $table->string('awb_number')->nullable();
            $table->string('reference_number')->nullable();
            $table->enum('export_status', ['pending', 'exporting', 'success', 'failed'])->default('pending');
            $table->unsignedInteger('retry_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->json('export_payload')->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();

            $table->unique(['stox_account_id', 'order_id']);
            $table->index(['export_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_orders');
    }
};

