<?php
// Cancelled requests read Cancelled and an open order can still be closed. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Invoice,InvoiceItem,InvoiceTransaction,Service};
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
staffPanel();
foreach(['admin.domains.operations.credit','admin.domains.operations.disposition'] as $permission){$permissions[]=$permission;}
$staff->role->update(['permissions'=>[...$permissions,'admin.domains.costs','admin.invoices.update']]);$staff->refresh();
$op=$new(placeholderComFixtureName('truth-cancelled-placeholder.com'),paid:false);
// The demo cleanup wrote this state by raw SQL; no code writes it, so old rows are all that exist.
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'cancelled']);
$request=fn()=>Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertSuccessful();
$request()->assertSee('Cancelled')->assertSee('Not charged')->assertSee('Open domain')->assertSee('Cancel the order on the domain page')
    ->assertDontSee('Under review')->assertDontSee('Status missing')->assertDontSee('Missing');
same('registration_pending',$op->domain->fresh()->status,'The domain stays pending until the order closes');
$domain=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$op->domain_id])->assertSuccessful()->assertActionVisible('retire_unpaid');
$domain->callAction('retire_unpaid',['reason'=>'Close the order left open by cleanup'])->assertHasNoActionErrors()->assertNotified('Order cancelled');
same('cancelled',$op->domain->fresh()->status,'Cancel order closes the domain');
same(['rejected','acquisition_cancelled_unpaid'],[$op->fresh()->state,$op->fresh()->safe_code],'The closed order keeps its reason');
same(null,$op->domain->fresh()->reservation_name,'The name is released');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$op->domain_id])->assertActionDoesNotExist('retire_unpaid');
// A closed unpaid order reads Cancelled too, with no money rows.
$request()->assertSee('Cancelled')->assertSee('Order cancelled: not paid')->assertSee('Not charged')
    ->assertDontSee('Failed')->assertDontSee('No credit added')->assertDontSee('Missing')->assertActionHidden('credit');
// A payment after closing changes the story, and staff are sent to its review.
DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
$request()->assertSee('Order cancelled: payment recorded later');
InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded']);
$retirement->inspect($op->domain_id);
$request()->assertSee('Cancelled')->assertSee('Order cancelled: payment recorded later')->assertSee('Record payment review')->assertSee('Open domain')
    ->assertSee('Not charged')->assertDontSee('not paid')->assertDontSee('Missing')->assertActionHidden('credit');
// A later failed try cannot hide an earlier real payment or its staff review.
InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'failed']);
$retirement->inspect($op->domain_id);
$request()->assertSee('Order cancelled: payment recorded later')->assertSee('Record payment review')
    ->assertDontSee('payment attempted later');
// A failed attempt stays visible, but is not money and needs no payment review.
$attempt=$new(placeholderComFixtureName('truth-failed-attempt-placeholder.com'),paid:false);
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$attempt->domain_id])->assertSuccessful()
    ->callAction('retire_unpaid',['reason'=>'Close the order left open by cleanup'])->assertNotified('Order cancelled');
InvoiceTransaction::create(['invoice_id'=>$attempt->invoice_id,'amount'=>$attempt->amount,'fee'=>'0.00','status'=>'failed']);
$retirement->inspect($attempt->domain_id);
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$attempt->id])->assertSuccessful()
    ->assertSee('Order cancelled: payment attempted later')->assertDontSee('Record payment review')->assertSee('Not charged')
    ->assertDontSee('payment recorded later')->assertDontSee('No credit added')->assertActionHidden('credit');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$attempt->domain_id)->whereNull('resolved_at')->count(),'A failed attempt alone needs no staff alert');
// Money on another invoice for this service means the order is not unpaid.
$money=$new(placeholderComFixtureName('truth-second-invoice-placeholder.com'),paid:false);
$second=Invoice::withoutEvents(fn()=>Invoice::create(['user_id'=>$money->domain->user_id,'currency_code'=>'USD','status'=>'paid','due_at'=>$clock->now()]));
InvoiceItem::withoutEvents(fn()=>InvoiceItem::create(['invoice_id'=>$second->id,'reference_type'=>(new Service)->getMorphClass(),'reference_id'=>$money->service_id,'description'=>'Retained charge','quantity'=>1,'price'=>'1.00']));
InvoiceTransaction::create(['invoice_id'=>$second->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$money->domain_id])->assertSuccessful()
    ->callAction('retire_unpaid',['reason'=>'Close the order left open by cleanup'])->assertNotified("Couldn't cancel order");
same(['registration_pending','awaiting_payment'],[$money->domain->fresh()->status,$money->fresh()->state],'A paid service keeps its order');
same('pending',Service::find($money->service_id)->status,'A paid service stays open');
// The same goes for an invoice under another owner, for staff and for the automatic close.
$foreign=$new(placeholderComFixtureName('truth-foreign-invoice-placeholder.com'),paid:false);
$theirs=Invoice::withoutEvents(fn()=>Invoice::create(['user_id'=>commerceUser()->id,'currency_code'=>'USD','status'=>'paid','due_at'=>$clock->now()]));
InvoiceItem::withoutEvents(fn()=>InvoiceItem::create(['invoice_id'=>$theirs->id,'reference_type'=>(new Service)->getMorphClass(),'reference_id'=>$foreign->service_id,'description'=>'Retained charge','quantity'=>1,'price'=>'1.00']));
InvoiceTransaction::create(['invoice_id'=>$theirs->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$foreign->domain_id])->assertSuccessful()
    ->callAction('retire_unpaid',['reason'=>'Close the order left open by cleanup'])->assertNotified("Couldn't cancel order");
same(['registration_pending','awaiting_payment'],[$foreign->domain->fresh()->status,$foreign->fresh()->state],'Money under another owner keeps the order');
$cancel($foreign);$retirement->inspect($foreign->domain_id);
same(['registration_pending',$foreign->domain->domain],[$foreign->domain->fresh()->status,$foreign->domain->fresh()->reservation_name],'The automatic close keeps the name');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$foreign->domain_id)->where('code','acquisition_retirement_review')->exists(),'The automatic close asks for a review');
echo "Cancelled request passed.\n";
$nativeSuccessful=true;
