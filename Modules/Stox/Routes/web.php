<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('stox')->group(function (): void {
    Route::get('/', static function (): array {
        return [
            'message' => 'Stox module web routes placeholder',
        ];
    });
});

