<?php
// Enom 444f31c routes; MIT, Copyright (c) 2026 Autonomic Engineering. Not loaded by any provider.
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController as Action;
use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController as Owner;
// Definitions are dormant; install39 must load them before the global input middleware runs.
TrimStrings::except(['transfer_code']);
Route::middleware(['web','auth',MustVerfiyEmail::class,'throttle:60,1,domains.owner'])->group(function():void {
    Route::post('/domains/{domain}/setup',[Action::class,'setup'])->whereNumber('domain')->name('domains.domains.setup-save')->block(10,10);
    Route::post('/domains/{domain}/account-contacts',[Action::class,'contacts'])->whereNumber('domain')->name('domains.domains.contacts-save')->block(10,10);
    Route::post('/domains/{domain}/transfer-code',[Action::class,'code'])->whereNumber('domain')->name('domains.domains.code-save')->block(10,10);
    Route::post('/domains/{domain}/manage/{action}',[Action::class,'manage'])->whereNumber('domain')->where('action',implode('|',Action::ACTIONS))->name('domains.domains.manage')->block(10,10);
    Route::get('/domains',[Owner::class,'index'])->name('domains.domains.index');
    Route::get('/domains/transfer',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedTransferController::class,'show'])->name('domains.domains.transfer');
    Route::get('/domains/{domain}',[Owner::class,'show'])->whereNumber('domain')->name('domains.domains.show');
    Route::get('/domains/{domain}/setup',[Owner::class,'setup'])->whereNumber('domain')->name('domains.domains.setup');
    Route::get('/domains/{domain}/transfer-code',[Owner::class,'code'])->whereNumber('domain')->name('domains.domains.code');
});
