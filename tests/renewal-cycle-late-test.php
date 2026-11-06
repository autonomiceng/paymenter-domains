<?php
// Enom d1392a8 complimentary/cancelled-unpaid cycles; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
$cycleFixtureGroup='late';
require __DIR__.'/Support/renewal-cycle-fixture.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RenewalFunding};
// Scheduled replacement cases retain the original scheduled-billing setup.
$product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
// Money can land after the week: Stripe writes no local row until its first webhook, so a closed invoice can still be paid. It all becomes credit.
config(['settings.credits_auto_use'=>true]);$refunds=new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);$cash=static fn()=>PricingRule::minor((string)($user->credits()->first()?->amount??'0.00'));$pay=App\Helpers\ExtensionHelper::class;
$closed=static function(string $name)use($earlyRenewal,$cycles,$clock,$at,$user):array {
    $user->credits()->update(['amount'=>'0.00']);$made=$earlyRenewal($name,'2027-08-01T12:00:00Z');config(['settings.credits_auto_use'=>true]);$clock->instant=$at('2027-08-08T12:00:00Z');$cycles->closeEarlyUnpaid($made[1]->id);same('cancelled',$made[3]->fresh()->status,'The week closes the invoice');
    $clock->instant=$at('2027-08-09T12:00:00Z');return $made;
};
$notes=static fn($domain)=>Operation::where('domain_id',$domain->id)->get()->map(fn($op)=>\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::lateNote($op))->filter()->values()->all();
$words=static fn(string $amount)=>'Paid after this invoice closed. We added '.$amount.' to account credit. You can apply it on an invoice.';
[$late,$lateDomain,,$lateInvoice]=$closed('renew-late-partial-placeholder.com');
$pay::addPayment($lateInvoice->id,null,'1.00');$refunds->retryPending();
same(100,$cash(),'A $1 payment on a closed invoice becomes $1 of credit');
same([$words('$1.00')],$notes($lateDomain),'The closed request shows the amount and where it goes');
same($words('$1.00'),\Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::requestStatus($late->fresh())['reason'],'Staff read the same words');
same(0,$refunds->retryPending(),'The next sweep changes nothing');same(100,$cash(),'A payment is credited once');
same(1,Operation::where('domain_id',$lateDomain->id)->where('action','renew')->count(),'Late money starts no renewal');
[$twice,$twiceDomain,,$twiceInvoice]=$closed('renew-late-twice-placeholder.com');$amount=PricingRule::minor($twice->amount);
foreach([1,2] as $n){$pay::addPayment($twiceInvoice->id,null,$twice->amount);$refunds->retryPending();same($n*$amount,$cash(),'Full payment '.$n.' on a closed invoice becomes credit');}
same([$words(\Paymenter\Extensions\Servers\Domains\Services\RenewalReminders::money(PricingRule::decimal(2*$amount)))],$notes($twiceDomain),'The note adds up what was credited');
[$frozen,,,$frozenInvoice]=$closed('renew-late-frozen-placeholder.com');$first=$pay::addPayment($frozenInvoice->id,null,$frozen->amount);
$refund=['amount'=>$frozen->amount,'payments'=>[['id'=>(int)$first->id,'amount_minor'=>PricingRule::minor($frozen->amount)]],'to'=>'account_credit'];$frozen=$frozen->fresh();$frozen->update(['funding'=>[...($frozen->funding??[]),'refund'=>$refund]]);
$pay::addPayment($frozenInvoice->id,null,'2.00');$refunds->retryPending();
same([200,$refund],[$cash(),$frozen->fresh()->funding['refund']],'A later payment is credited and the frozen credit stays as it was');
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
echo "Native renewal cycle matrix passed\n"; $nativeSuccessful=true;
