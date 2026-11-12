<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-cases.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;
// Missing account contacts keep native callbacks from automatically readmitting the hold.
$phone=$user->properties()->where('key','phone')->sole();$savedPhone=$phone->value;$phone->update(['value'=>'']);
$late=$new(placeholderComFixtureName('paid-late-contact-placeholder.com'),paid:false,setup:false);$before=$writer->calls;$paid($late);
same($before,$writer->calls,'Incomplete contacts never send');same('held',$late->fresh()->state,'Contact setup holds');
$nativeBeforeReplay=nativeFinancialSnapshot($late);$heldBeforeReplay=$late->fresh()->getRawOriginal();
$manual($late);event(new App\Events\Invoice\Paid(Invoice::find($late->invoice_id)));same('held',$late->fresh()->state,'Native wake never readmits contact hold');
same($heldBeforeReplay,$late->fresh()->getRawOriginal(),'Native contact-hold replay preserves operation bytes');
same($nativeBeforeReplay,nativeFinancialSnapshot($late),'Native contact-hold replay preserves native/financial state (reminder alerts and deliveries excluded)');
$phone->update(['value'=>$savedPhone]);ContactRecovery::replace($user,$late->id,'Owner supplied late contacts');$manual($late);same($before+1,$writer->calls,'Audited contact recovery permits one send');

$nativeSuccessful=true;
