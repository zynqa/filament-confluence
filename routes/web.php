<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Zynqa\FilamentConfluence\Http\Controllers\ConfluenceImageProxyController;

Route::middleware(config('filament-confluence.image_proxy.middleware', ['web', 'auth']))
    ->group(function (): void {
        Route::get('/confluence/images/{source}', ConfluenceImageProxyController::class)
            ->where('source', '[A-Za-z0-9_-]+')
            ->name('filament-confluence.images.show');
    });
