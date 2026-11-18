<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/Support/departure-fixture.php';
$invoice=$issueDepartureRenewal();
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureBlocked,DepartureChecks,DepartureProof,HoldingDeparture,HoldingLocks,MoneyOutcome,ReminderSchedules,Reminders,RenewalOwnership,StaffHoldingReview,StaffReviewRefused};

$nativeSuccessful=false;
staffPanel();
same(true,DepartureChecks::suspected($d->fresh()),'The fixture holding is under review');
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$departure=new HoldingDeparture($clock);
$ticket=static fn($actor)=>StaffHoldingReview::ticket((int)$actor->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$confirm=static fn($actor,string $reason='other_registrar')=>$departure->confirm($actor,(int)$d->id,$ticket($actor),$reason,'Checked the registrar account.');
$state=static fn()=>[$nativeMoney(),$d->fresh()->status,$d->fresh()->service->status,$renewal->fresh()->state,
    DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count()];
$blocked=static function(\Closure $call,int $line,string $why)use($state):void{
    $before=$state();$result=$call();
    same(MoneyOutcome::Failed,$result->outcome,$why.': refused');
    same($line,$result->error instanceof DepartureBlocked?$result->error->block['line']:null,$why.': blocking line');
    same($before,$state(),$why.': everything rolled back');
};
$manager=staffUser([Staff::VIEW,'admin.domains.manage']);$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);

// Permission: managing domains is not enough to confirm a departure.
same(true,array_key_exists(HoldingDeparture::PERMISSION,Staff::permissions()),'Role editors can grant the depart permission');
$before=$state();$result=$confirm($manager);
same([MoneyOutcome::Failed,true],[$result->outcome,$result->error instanceof StaffReviewRefused],'Confirmation needs its own permission');
same($before,$state(),'A refused confirmation changes nothing');
$page=static function($actor)use($d){Illuminate\Support\Facades\Auth::setUser($actor);return Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful();};
$page($manager)->assertActionHidden('confirm_departure');
$page($staff)->assertActionVisible('confirm_departure')->assertActionEnabled('confirm_departure');
same(null,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block'],'The open unpaid renewal needs no block');
same([(int)$renewal->id],HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['close'],'The preview closes the open unpaid renewal');

// Line 8: renewal records that don't agree cannot be closed safely.
$price=DB::table('invoice_items')->where('id',$renewal->invoice_item_id)->value('price');
DB::table('invoice_items')->where('id',$renewal->invoice_item_id)->update(['price'=>'1.00']); // Synthetic retained-data conflict.
same(HoldingDeparture::RENEWAL_REVIEW,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block']['line'],'The preview shows line 8');
// The blocking line links its records: the invoice and the renewal request.
Illuminate\Support\Facades\Auth::setUser($staff);
$links=Paymenter\Extensions\Servers\Domains\Admin\Support\DepartureConfirm::line(HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block'],$d->fresh())[2];
same(['departure_invoice','departure_request'],array_column($links,0),'Line 8 links the invoice and the request');
same(true,str_contains((string)$links[1][2],'/'.$renewal->id),'The request link opens the blocking renewal request');
$page($staff)->assertActionDisabled('confirm_departure');
$blocked(fn()=>$confirm($staff),HoldingDeparture::RENEWAL_REVIEW,'Line 8');
DB::table('invoice_items')->where('id',$renewal->invoice_item_id)->update(['price'=>$price]);

// Line 4: more paid out than received on the renewal invoice.
$refund=App\Models\InvoiceTransaction::create(['invoice_id'=>$invoice->id,'gateway_id'=>$gateway->id,'amount'=>'-5.00','status'=>App\Enums\InvoiceTransactionStatus::Succeeded,'transaction_id'=>'synthetic-refund']);
$money=$nativeMoney();
same(HoldingDeparture::OVERDRAWN,HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block']['line'],'The preview shows line 4');
same($money,$nativeMoney(),'The preview writes no money');
$blocked(fn()=>$confirm($staff),HoldingDeparture::OVERDRAWN,'Line 4');
DB::table('invoice_transactions')->where('id',$refund->id)->delete();

// Line 1: unreadable money evidence stays blocked, linked to its invoice.
$raw=DB::table('domains_operations')->where('id',$renewal->id)->value('funding');
// Synthetic retained-data variant: a return plan the shared reader cannot read.
DB::table('domains_operations')->where('id',$renewal->id)->update(['funding'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode([...($renewal->fresh()->funding??[]),'refund'=>['to'=>'nowhere']]))]);
$block=HoldingDeparture::preview($d->fresh(),$clock->now()->getTimestamp())['block'];
same([HoldingDeparture::UNREADABLE,(int)$invoice->id],[$block['line'],$block['invoice']],'The preview shows line 1 with its invoice');
$blocked(fn()=>$confirm($staff),HoldingDeparture::UNREADABLE,'Line 1');
DB::table('domains_operations')->where('id',$renewal->id)->update(['funding'=>$raw]);

// Line 9: an absence read older than 24 hours needs Check again first.
$at=$clock->instant;$clock->instant=$clock->now()->modify('+25 hours');
$blocked(fn()=>$confirm($staff),HoldingDeparture::STALE,'Line 9');
$clock->instant=$at;

// The owner's watch linked to this domain is handed back; another customer's watch of the same name is not touched.
$watchRow=static fn(int $owner,array $extra)=>DB::transaction(fn()=>Paymenter\Extensions\Servers\Domains\Models\WatchName::create(['user_id'=>$owner,'canonical_name'=>$d->domain,'psl_revision'=>str_repeat('a',64),'notice_preferences'=>[],...$extra]));
$linked=$watchRow((int)$d->user_id,['state'=>'linked','registration'=>'platform','managed_domain_id'=>$d->id]);
$other=$watchRow((int)$manager->id,['state'=>'active','registration'=>'external']);$otherBefore=$other->fresh()->getAttributes();
// Confirmation: one decision closes the unpaid renewal, cancels the service and freezes the record.
$credit=DB::table('credits')->where('user_id',$d->user_id)->get()->map(fn($r)=>(array)$r)->all();
// A stopped sync has nothing to resume once the domain departs; an unrelated alert stays open.
$follow=$d->fresh()->observation['followup']??[];
DB::table('domains_observation_work')->updateOrInsert(['domain_id'=>$d->id,'generation'=>$d->authority_generation,
    'phase'=>isset($follow['operation_key'])?hash('sha256',$follow['operation_key']):'holding'],
    ['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'due_at'=>0,'safe_code'=>'domain_not_in_account']);
Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($d->fresh(),'observation_review',$clock->now()->getTimestamp());
Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($d->fresh(),'unknown_expiry',$clock->now()->getTimestamp());
$page($staff)->callAction('confirm_departure',['reason'=>'other_account','note'=>'Checked the registrar account.'])->assertHasNoActionErrors();
$held=$d->fresh();
$openAlert=static fn(string $code)=>DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',$code)->whereNull('resolved_at')->count();
same([0,1],[$openAlert('observation_review'),$openAlert('unknown_expiry')],'Confirmation resolves the stopped-sync alert and keeps other alerts open');
same(['departed','other_account',true],[$held->status,$held->renewal_policy['departure']['reason'],DepartureProof::matches($held)],'The holding is departed with its frozen record');
same(['cancelled','rejected','renewal_cancelled_unpaid'],[$invoice->fresh()->status,$renewal->fresh()->state,$renewal->fresh()->safe_code],'The unpaid renewal invoice is cancelled and its request closed unsent');
same('cancelled',$held->service->status,'The native service is cancelled');
same($credit,DB::table('credits')->where('user_id',$d->user_id)->get()->map(fn($r)=>(array)$r)->all(),'An unpaid renewal adds no account credit');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',RenewalOwnership::REVIEW)->whereNull('resolved_at')->count(),'Confirmation resolves the departure review');
same([null,true],[$held->reservation_name,DepartureProof::verified($held)],'Confirmation releases the name with the frozen record');
same(['retired',null],[$linked->fresh()->state,$linked->fresh()->managed_domain_id],'A linked watch with no hosting source is retired, not attached elsewhere');
same(1,Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$linked->id)->where('event','registration_departed')->count(),'The handoff is audited');
same($otherBefore,$other->fresh()->getAttributes(),"Another customer's watch of the same name is unchanged");
same(['id'=>(int)$linked->id,'state'=>'retired'],(array_slice($held->renewal_policy['staff_holdings'],-1)[0]['watch']??null),'The confirmation history records the watch outcome');
$again=$departure->confirm($staff,(int)$d->id,'',  'other_account','Checked the registrar account.');
same(MoneyOutcome::Failed,$again->outcome,'A departed holding cannot be confirmed again');
$refusedWrite=false;try{$held->update(['status'=>'active']);}catch(DomainException){$refusedWrite=true;}
same(true,$refusedWrite,'Departed status is retained');
// Retained read failures cannot reopen the stopped-sync alert after confirmation.
ReminderSchedules::enroll((int)$d->server_id);
$reminders=new Reminders($clock,$bindings);
foreach([1,2] as $sweep){
    DB::table('domains_reminder_schedules')->where('server_id',$d->server_id)->update(['due_at'=>0,'cursor'=>$d->id-1]);
    $reminders->sweep(accounts:1,limit:1);
    same(0,$openAlert('observation_review'),'The stopped-sync alert stays resolved after reminder sweep '.$sweep);
    same(0,$openAlert('reminder_failed'),'The departed reminder inspection completes without failure');
}
echo "Departure confirmation closes unpaid renewals in one decision and refuses blocked holdings.\n";
$nativeSuccessful=true;
