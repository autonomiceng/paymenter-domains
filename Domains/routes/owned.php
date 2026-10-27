<?php
// Enom 444f31c routes; MIT, Copyright (c) 2026 Autonomic Engineering. Not loaded by any provider.
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController as Action;
use App\Http\Middleware\MustVerfiyEmail;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController as Owner;
// Byte-sensitive POST values are decoded inside their owned controller handlers.
Route::middleware(['web','auth',MustVerfiyEmail::class,'throttle:60,1,domains.owner'])->group(function():void {
    Route::post('/domains/{domain}/setup',[Action::class,'setup'])->whereNumber('domain')->name('domains.domains.setup-save')->block(10,10);
    Route::post('/domains/{domain}/account-contacts',[Action::class,'contacts'])->whereNumber('domain')->name('domains.domains.contacts-save')->block(10,10);
    Route::post('/domains/{domain}/transfer-code',[Action::class,'code'])->whereNumber('domain')->name('domains.domains.code-save')->block(10,10);
    Route::post('/domains/{domain}/auto-renew',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerAutoRenewController::class,'save'])->whereNumber('domain')->name('domains.domains.auto-renew')->block(10,10);
    Route::post('/domains/{domain}/renew',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerRenewalController::class,'renew'])->whereNumber('domain')->name('domains.domains.renew')->block(10,10);
    Route::post('/domains/{domain}/manage/{action}',[Action::class,'manage'])->whereNumber('domain')->where('action',implode('|',Action::ACTIONS))->name('domains.domains.manage')->block(10,10);
    Route::get('/domains',[Owner::class,'index'])->name('domains.domains.index');
    Route::get('/domains/help',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerHelpController::class,'show'])->name('domains.help');
    Route::get('/domains/watches/{id}/transfer',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchTransferController::class,'show'])->whereNumber('id')->name('domains.watch.transfer');
    Route::post('/domains/watches/{id}/transfer',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchTransferController::class,'check'])->whereNumber('id')->name('domains.watch.transfer-check')->block(10,10);
    Route::get('/domains/transfer',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedTransferController::class,'show'])->name('domains.domains.transfer');
    Route::get('/domains/{domain}',[Owner::class,'show'])->whereNumber('domain')->name('domains.domains.show');
    Route::get('/domains/{domain}/setup',[Owner::class,'setup'])->whereNumber('domain')->name('domains.domains.setup');
    Route::get('/domains/{domain}/transfer-code',[Owner::class,'code'])->whereNumber('domain')->name('domains.domains.code');
    Route::get('/domains/checks/{kind}/{id}',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchNoticeController::class,'show'])->where('kind','watch|managed')->whereNumber('id')->name('domains.checks.show');
    Route::post('/domains/checks/{kind}/{id}',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchNoticeController::class,'save'])->where('kind','watch|managed')->whereNumber('id')->name('domains.checks.save')->block(10,10);
    Route::get('/domains/check-notices/deliveries/{delivery}',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchNoticeReviewController::class,'show'])->whereNumber('delivery')->name('domains.checks.review');
    Route::post('/domains/check-notices/deliveries/{delivery}',[\Paymenter\Extensions\Servers\Domains\Http\Controllers\WatchNoticeReviewController::class,'resend'])->whereNumber('delivery')->name('domains.checks.resend')->block(10,10);
});
