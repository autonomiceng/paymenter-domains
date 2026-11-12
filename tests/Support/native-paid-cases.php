<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-fixture.php';
require_once __DIR__.'/reserved-fixtures.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;

if(defined('NATIVE_PAID_SEED_CREDIT')){$user->credits()->firstOrCreate(['currency_code'=>'USD'],['amount'=>'0.00']);}
$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.invoices.update','admin.invoices.view']);
$refunds=new Refunds($clock);$retirement=app(AcquisitionRetirement::class);
$phone=$user->properties()->where('key','phone')->sole();$savedPhone=$phone->value;
