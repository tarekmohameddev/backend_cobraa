<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stox_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('base_url')->default(config('stox.default_base_url'));
            $table->text('bearer_token');
            $table->string('webhook_signature')->nullable();
            $table->json('settings')->nullable();
            $table->json('default_payment_mapping')->nullable();
            $table->json('auto_export_statuses')->nullable();
            $table->unsignedInteger('export_delay_minutes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stox_accounts');
    }
};

