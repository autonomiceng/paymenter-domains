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
same(['On','Awaiting payment: invoice #'.$replacement->id],[$facts['Renewal invoices']['value'],$facts['Renewal invoices']['sub']],'Billing names the current invoice before another reminder check');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()
    ->assertSee('Awaiting payment: invoice #'.$replacement->id)->assertDontSee('Unpaid renewal invoice cancelled')
    ->assertSee('Registrar request needs a check');
$reminders->remind($d->id);
expect(!$openAlert(),'An open replacement resolves the old cancelled-invoice warning');
$reminders->remind($d->id);
expect(!$openAlert(),'Repeated reminder checks do not reopen the old warning');
same($oldRow,$old->fresh()->getAttributes(),'Old request and its retirement audit stay in history');
same($invoiceRow,$oldInvoice->fresh()->getAttributes(),'Old cancelled invoice stays in history');
Livewire::actingAs($staff)->test(RequestsRelationManager::class,['ownerRecord'=>$d->fresh(),'pageClass'=>ViewDomain::class])
    ->assertSee('History')->assertSee('Request #'.$old->id)->assertSee('Renewal cancelled: not paid');

// If the replacement is itself cancelled, it becomes the latest unpaid renewal again.
$cycles->retireUnpaid($replacementOp->id);
$reminders->remind($d->id);$reminders->remind($d->id);
expect($openAlert(),'Cancelling the latest invoice restores the actionable warning');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()
    ->assertSee('Unpaid renewal invoice cancelled')->assertDontSee('Awaiting payment: invoice #'.$replacement->id);

echo "Current renewal invoice replaces the cancelled-invoice warning without changing history.\n";
$nativeSuccessful=true;
