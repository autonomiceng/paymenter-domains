<?php
// Adapted from Enom 444f31c routes/web.php; MIT, Copyright (c) 2026 Autonomic Engineering.
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController as Storefront;
// Intentionally NOT loaded by any provider/boot.39 owns all registration and singleton native admission hooks.
Route::middleware('web')->group(function (): void {
    Route::get('/domains/register', [Storefront::class, 'search'])->name('domains.domains.search');
    Route::get('/domains/search', [Storefront::class, 'searchAlias'])->name('domains.domains.search.alias');
    Route::get('/domains/pricing', [Storefront::class, 'pricing'])->name('domains.domains.pricing');
    Route::post('/domains/check', [Storefront::class, 'check'])->name('domains.domains.check')->block(10, 10);
    Route::post('/domains/order', [Storefront::class, 'order'])->name('domains.domains.order')->block(10, 10);
});
