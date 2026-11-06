<?php
// Native late renewal payments; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$renewalFixtureNames=['renew-late-declined','renew-late-good','renew-late-open'];
require __DIR__.'/Support/renewal-late-cycle.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RenewalFunding};
// A card declined after the close leaves the scheduled renewal free to issue.
[$declined,$declinedDomain,,$declinedInvoice]=$closed('renew-late-declined-placeholder.com');
$pay::addFailedPayment($declinedInvoice->id,null,$declined->amount);same('cancelled',$declinedInvoice->fresh()->status,'A failed payment keeps the invoice closed');
$clock->instant=$at('2027-09-27T12:00:00Z');$next=$billing->invoice($declinedDomain->id);same(['pending',true],[$next->status,str_ends_with(Operation::where('invoice_id',$next->id)->sole()->operation_key,':replacement:1')],'A declined card does not block the scheduled renewal');
// A declined card is not money, so it does not stop a good payment after it. That credit then pays the scheduled invoice.
[$good,$goodDomain,,$goodInvoice]=$closed('renew-late-good-placeholder.com');
$pay::addFailedPayment($goodInvoice->id,null,$good->amount);$pay::addPayment($goodInvoice->id,null,$good->amount);$refunds->retryPending();same(PricingRule::minor($good->amount),$cash(),'The good payment becomes credit');
$clock->instant=$at('2027-09-27T12:00:00Z');$next=$billing->invoice($goodDomain->id);same(['paid',0],[$next->status,$cash()],'The next renewal invoice uses the credit');
// The replacement invoice was already open when the money landed. A checkout could be open on it, so the sweep leaves the credit to the customer.
[$first,$openDomain,,$firstInvoice]=$closed('renew-late-open-placeholder.com');$open=$billing->invoice($openDomain->id,$user);same('pending',$open->status,'The replacement waits for payment');
$pay::addPayment($firstInvoice->id,null,$first->amount);$refunds->retryPending();$kept=PricingRule::minor($first->amount);same($kept,$cash(),'The late money is credit');
$billing->invoice($openDomain->id);same(['pending',$kept,0],[$open->fresh()->status,$cash(),$open->transactions()->count()],'A sweep leaves an open invoice and the credit alone');
Livewire\Livewire::actingAs($user)->test(App\Livewire\Invoices\Show::class,['invoice'=>$open->fresh()])->set('selectedMethod','credit')->call('processPayment');
same(['paid',0,1],[$open->fresh()->status,$cash(),$open->transactions()->count()],'The customer applies the credit on the invoice');
// The check the registrar step runs before it sends the renewal.
Illuminate\Support\Facades\DB::transaction(function()use($open,$openDomain){$l=Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($openDomain->fresh());Paymenter\Extensions\Servers\Domains\Services\RenewalFunding::requireCurrent($l['operations']->firstWhere('invoice_id',$open->id),$l);});
same([],array_keys($registrations),'All independent acquisition scenarios ran');
echo "Closed renewal invoice replacement and customer credit payment passed\n"; $nativeSuccessful=true;
