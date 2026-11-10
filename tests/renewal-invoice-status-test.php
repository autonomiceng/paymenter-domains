<?php
// Current renewal invoice and retained cancellation history. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/reminders.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts,RenewalCycles};

staffPanel();Auth::login($staff);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
\Paymenter\Extensions\Servers\Domains\Services\PslReviewSchema::install();
[$d,$oldInvoice,$old]=$make(placeholderComFixtureName('renewal-invoice-status-placeholder.com'));
$cycles=new RenewalCycles($bindings,$clock);
$cycles->retireUnpaid($old->id);
$openAlert=static fn()=>DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','renewal_unpaid')->whereNull('resolved_at')->exists();
$reminders->remind($d->id);
expect($openAlert(),'The latest cancelled renewal needs attention when nothing replaces it');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()->assertSee('Unpaid renewal invoice cancelled');
$oldRow=$old->fresh()->getAttributes();$invoiceRow=$oldInvoice->fresh()->getAttributes();
$replacement=$billing->invoice($d->id,$d->service->user);
same('pending',$replacement->status,'A new renewal invoice is open for the same expiry');
$replacementOp=$d->operations()->where('invoice_id',$replacement->id)->sole();
same($old->base_expires_at->format('Y-m-d'),$replacementOp->base_expires_at->format('Y-m-d'),'Replacement is for the cancelled renewal cycle');
ObligationAlerts::record($d,'operation_review',$clock->now()->getTimestamp());
$facts=DomainFacts::for($d->fresh());
same(['Invoice #'.$replacement->id,'Awaiting payment'],[$facts['Open invoice']['value'],$facts['Open invoice']['sub']],'Billing names the current invoice before another reminder check');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()
    ->assertSee('Invoice #'.$replacement->id)->assertDontSee('Unpaid renewal invoice cancelled')
    ->assertSee('Registrar request needs a check');
$reminders->remind($d->id);
expect(!$openAlert(),'An open replacement resolves the old cancelled-invoice warning');
$reminders->remind($d->id);
expect(!$openAlert(),'Repeated reminder checks do not reopen the old warning');
same($oldRow,$old->fresh()->getAttributes(),'Old request and its retirement audit stay in history');
same($invoiceRow,$oldInvoice->fresh()->getAttributes(),'Old cancelled invoice stays in history');
Livewire::actingAs($staff)->test(RequestsRelationManager::class,['ownerRecord'=>$d->fresh(),'pageClass'=>ViewDomain::class])
    ->assertSee('Search changes')->assertSee('Request #'.$old->id)->assertSee('Renewal cancelled: not paid');

// Latest payment status is read from the transaction batch.
foreach (['failed'=>true,'processing'=>false] as $paymentStatus=>$declined) {
    App\Models\InvoiceTransaction::withoutEvents(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$replacement->id,'amount'=>'1.00','fee'=>'0.00','status'=>$paymentStatus]));
    $domain=$d->fresh();$inputs=Paymenter\Extensions\Servers\Domains\Services\DomainStateInputs::one($domain);
    DB::flushQueryLog();DB::enableQueryLog();
    $current=Paymenter\Extensions\Servers\Domains\Services\DomainStates::cycle($domain,$inputs,$clock->now())['current'];
    $reads=array_filter(DB::getQueryLog(),fn($q)=>str_contains($q['query'],'invoice_transactions'));
    DB::disableQueryLog();
    same($declined,$current['declined']??false,'The newest transaction determines the decline message');
    same([],$reads,'Projecting a batched renewal does not query its transactions again');
}
DB::table('invoice_transactions')->where('invoice_id',$replacement->id)->update(['status'=>'failed']);

// If the replacement is itself cancelled, it becomes the latest unpaid renewal again.
$cycles->retireUnpaid($replacementOp->id);
$reminders->remind($d->id);$reminders->remind($d->id);
expect($openAlert(),'Cancelling the latest invoice restores the actionable warning');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()
    ->assertSee('Unpaid renewal invoice cancelled')->assertDontSee('Awaiting payment: invoice #'.$replacement->id);

echo "Current renewal invoice replaces the cancelled-invoice warning without changing history.\n";
$nativeSuccessful=true;
