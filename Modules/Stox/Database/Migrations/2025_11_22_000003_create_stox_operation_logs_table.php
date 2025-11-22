<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stox_operation_logs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stox_account_id')
                ->nullable()
                ->constrained('stox_accounts')
                ->nullOnDelete();
            $table->foreignId('stox_order_id')
                ->nullable()
                ->constrained('stox_orders')
                ->nullOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('operation_type');
            $table->string('trigger_type')->default('manual');
            $table->unsignedInteger('http_status')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->text('error_message')->nullable();
            $table->longText('stack_trace')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['operation_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_operation_logs');
    }
};

