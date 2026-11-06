<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native owner renewal preference scenarios.
declare(strict_types=1);
require __DIR__.'/Support/owner-auto-renew.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Invoice,ServiceCancellation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,OwnerAutoRenewController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,OwnerAutoRenew,RenewalStop,RuntimeIneligible,RuntimeReason};
[$d,$binding]=$holder('owner-switch-placeholder.com');$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$save($d,false);
// A real native payment and synthetic registrar completion retain Off across the next cycle.
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
// The independent catalogue facts must still be fresh when the paid renewal executes.
activeCommerceFacts($clock->now(),$clock->now()->modify('+30 days'),activeFixturePorts($bindings));$observeRenewalCost($binding);
$invoice=$billing->invoice($d->id,$user);$op=Operation::where('invoice_id',$invoice->id)->sole();
same('manual_renewal',$op->funding['auto_pay']['reason'],'Manual invoice never collects Auto Pay');
same($invoice->id,$billing->invoice($d->id,$user)->id,'Duplicate request reuses manual debt');
ExtensionHelper::addPayment($invoice,null,$op->amount);same('paid',$invoice->fresh()->status,'Owner payment funds invoice');
$state->name=$d->domain;$state->price='8.00';$state->charge='8.00';
$clock->instant=new DateTimeImmutable('2027-10-18T11:59:00Z');Cache::flush();
same('pending',$runRenewal($op,$binding)->state,'Paid manual renewal executes with owner Off');
[$engine,$writer,$completion]=$compose($binding);
same('succeeded',$completion->run($op->id)->state,'Manual renewal completes');
same(false,$d->fresh()->renewal_policy['owner_auto_renew'],'Payment does not turn auto-renew on');
$clock->instant=new DateTimeImmutable('2028-09-27T12:00:00Z');same(null,$billing->invoice($d->id),'Off persists across cycles');
activeCommerceFacts($clock->now(),$clock->now()->modify('+30 days'),activeFixturePorts($bindings));$observeRenewalCost($binding);
$save($d,true);$next=$billing->invoice($d->id);expect($next->id!==$invoice->id,'On inside next billing window issues next debt');
same($next->id,$billing->invoice($d->id)->id,'Scheduled retry reuses next debt');
staffPanel();Auth::login(staffUser([Staff::VIEW]));$save($d,false);
$facts=DomainFacts::for($d->fresh());same([['Off','Turned off by customer'],['Invoice #'.$next->id,'Awaiting payment']],[[$facts['Renewal invoices']['value'],$facts['Renewal invoices']['sub']],[$facts['Open invoice']['value'],$facts['Open invoice']['sub']]],'Staff distinguishes owner Off; issued invoices stay payable');
Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager::class,['ownerRecord'=>$d->fresh(),'pageClass'=>Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class])
    ->assertSee('Auto-renew turned off')->assertSee(trim($user->first_name.' '.$user->last_name)?:'Customer');
$staffActor=staffUser([Staff::VIEW]);
DB::table('domains_watch_audit')->where('subject_type','domain')->where('subject_id',$d->id)->where('event','owner_auto_renew')->update(['data'=>'invalid ciphertext']);
Auth::login($staffActor);
same(['Off','Turned off by customer'],[DomainFacts::for($d->fresh())['Renewal invoices']['value'],DomainFacts::for($d->fresh())['Renewal invoices']['sub']],'Unreadable audit does not break staff facts');
$native=\Livewire\Livewire::actingAs($staffActor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id]);
$native->assertSuccessful()->assertSee('Billing');
\Livewire\Livewire::actingAs($staffActor)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager::class,['ownerRecord'=>$d->fresh(),'pageClass'=>Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class])
    ->assertSuccessful()->assertSee('Auto-renew changed')->assertDontSee('invalid ciphertext');
echo "Owner renewal payment, later cycle and staff facts passed.\n";$nativeSuccessful=true;
