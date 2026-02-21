<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_activity_logs', static function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('activity_type', 50);
            $table->text('description');

            $table->unsignedInteger('lines_added')->default(0);
            $table->unsignedInteger('units_added')->default(0);
            $table->unsignedInteger('lines_removed')->default(0);
            $table->unsignedInteger('units_removed')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
            $table->index(['user_id', 'activity_type']);
            $table->index(['activity_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_activity_logs');
    }
};

