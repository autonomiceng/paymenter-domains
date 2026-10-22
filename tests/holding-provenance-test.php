<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic retained-state contract only.
declare(strict_types=1);
require __DIR__.'/Support/reminders.php';
require __DIR__.'/Support/owner-pages.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Illuminate\Support\Facades\{DB,Schema,Queue};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingProvenanceSchema,Installation,RuntimeDispatch,DomainObservation,RenewalAuthority,RenewalReminders,DomainReservations};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
HoldingProvenanceSchema::install();$enable();
[$d,$invoice,$debt]=$make(reservedFixtureName('example.com'));
$original=$d->getRawOriginal();$nativeBefore=$nativeMoney();
// An existing purchase remains untouched by the additive migration and reinstall.
same(null,$d->provenance,'No purchase provenance fabricated for preexisting rows');
HoldingProvenanceSchema::install();same($original,$d->fresh()->getRawOriginal(),'Migration rerun preserves original ciphertext/history');
$dispatch=new RuntimeDispatch($clock);expect($dispatch->current($debt),'Purchased holding remains eligible for dispatch checks');
$l=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d));
$alerts=[];$schedule=(object)['before_days'=>'[7]','after_days'=>'[3]'];
$planned=DB::transaction(fn()=>RenewalReminders::plan($l,$schedule,$clock->now(),$alerts,$bindings));expect($planned!==null,'Purchased renewal notice has genuine eligible debt');
expect(RenewalAuthority::completed($d->operations()->where('action','register')->sole(),$d,\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($l['account'])),'Purchased completion remains strict and usable');
$ob=new DomainObservation($readBindings,$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock));
$before=count($observation->calls);$ob->run($d->id);expect(count($observation->calls)>$before,'Purchased observer performs synthetic reads');
$original=$d->fresh()->getRawOriginal();$opBefore=$d->operations()->orderBy('id')->get()->map(fn($o)=>$o->getRawOriginal())->all();
// Each independent pending signal must hold even when another field drifts to an active label.
foreach ([['status'=>'adoption_pending','provenance'=>null],['status'=>'active','provenance'=>'adoption_pending']] as $pending) {
    DB::table('domains_domains')->where('id',$d->id)->update([...$pending,'billing_managed'=>true]);$d->refresh();
    expect($d->adoptionPending(),'Either retained pending marker refuses authority');
    same(false,$dispatch->current($debt),'Pending domain cannot dispatch existing renewal debt');
    Queue::fake();$dispatch->enqueue($debt->id,true);Queue::assertNothingPushed();
    commerceReject(fn()=>$billing->invoice($d->id,$user));
    commerceReject(fn()=>$claims->claim($debt->id));
    $calls=count($observation->calls);commerceReject(fn()=>$ob->run($d->id));same($calls,count($observation->calls),'Pending observer makes no reads');
    same(false,$reminders->remind($d->id),'Pending domain sends no renewal notice');
    $pendingLocks=[...$l,'domain'=>$d];$alerts=[];
    same(null,RenewalReminders::plan($pendingLocks,$schedule,$clock->now(),$alerts,$bindings),'Pending reminder planner excludes even active drift');
    $expired=clone $d;$expired->provider_expires_at=\Carbon\CarbonImmutable::instance($clock->now())->subDays(3);$expiredLocks=[...$l,'domain'=>$expired];
    same(null,RenewalReminders::plan($expiredLocks,$schedule,$clock->now(),$alerts,$bindings),'Pending expired notice is excluded independently of funding proof');
    same(false,RenewalAuthority::completed($d->operations()->where('action','register')->sole(),$d,\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding($l['account'])),'No pending completion authority');
    $work=new \Paymenter\Extensions\Servers\Domains\Services\ObservationWork($ob,$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock));$work->enroll($d->server_id);
    DB::table('domains_observation_work')->where('domain_id',$d->id)->delete();$work->tick();same(0,DB::table('domains_observation_work')->where('domain_id',$d->id)->count(),'Pending name never enrolled for managed observation');
    $f=$d->observation['followup']??[];$phase=isset($f['operation_key'])&&($f['generation']??null)===$d->authority_generation?hash('sha256',$f['operation_key']):'holding';
    DB::table('domains_observation_work')->insert(['domain_id'=>$d->id,'server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'generation'=>$d->authority_generation,'phase'=>$phase,'due_at'=>0]);$work->tick();
    same('superseded',DB::table('domains_observation_work')->where('domain_id',$d->id)->value('safe_code'),'Already scheduled pending work cannot be claimed');same($calls,count($observation->calls),'Pending scheduled work performs no provider reads');
    $dto=$owners->present($d);same('Being set up',$dto->status,'Neutral pending label');same(null,$dto->expiry,'No confirmed expiry shown');same(null,$dto->renewalPrice,'No renewal quote');same([],$dto->management,'No management forms');expect($dto->setupPending,'Pending notice shown');
    storefrontViews();$html=view('domains::show',['domain'=>$dto,'keys'=>[]])->render();
    expect(str_contains($html,'Our team will confirm this domain’s registration.'),'Customer knows next step');
    $visible=strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',$html));
    foreach(['Enom','NameSilo','unmanaged'] as $text){expect(!str_contains($visible,$text),'Neutral held page: '.$text);}
    Illuminate\Support\Facades\Auth::setUser($staff);expect(str_contains(DomainFacts::for($d)['Setup reason'],'purchase evidence'),'Staff see reason');
    expect(str_contains(implode(' ',DomainFacts::for($d)['Open domain invoices']),'payable'),'Staff sees retained payable renewal');
    $registration=$d->operations()->where('action','register')->sole();DB::table('invoices')->where('id',$registration->invoice_id)->update(['status'=>'pending']);
    expect(in_array('#'.$registration->invoice_id.' — payable; review before collecting',DomainFacts::for($d)['Open domain invoices'],true),'Open registration invoice also visible');
    DB::table('invoices')->where('id',$registration->invoice_id)->update(['status'=>'paid']);
    DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->delete();
    DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'paid']);
    app()->instance(RuntimeDispatch::class,$dispatch);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Reminders::class,$reminders);
    (new \Paymenter\Extensions\Servers\Domains\Services\RuntimeWork($clock,new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts))->invoice($invoice->id);Queue::assertNothingPushed();
    expect(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','operation_review')->exists(),'Payment creates review alert without dispatch');
    DB::table('invoices')->where('id',$invoice->id)->update(['status'=>'pending']);
    if($pending['status']==='adoption_pending'){commerceReject(fn()=>$d->update(['status'=>'active']));$d->refresh();}
    $claims->holdUnclaimed($debt->id,'gate_off');
    same($opBefore,$d->operations()->orderBy('id')->get()->map(fn($o)=>$o->getRawOriginal())->all(),'Unclaimed pending obligation is immediately unchanged');
    $running=$debt->getRawOriginal();DB::table('domains_operations')->where('id',$debt->id)->update(['state'=>'running','lease_expires_at'=>'2020-01-01 00:00:00']);
    $kept=(array)DB::table('domains_operations')->where('id',$debt->id)->first();$claims->holdUnclaimed($debt->id,'gate_off');same($kept,(array)DB::table('domains_operations')->where('id',$debt->id)->first(),'Pending interrupted hold never resolves state');same(null,$claims->claim($debt->id),'Pending running claim is never reclaimed');same($kept,(array)DB::table('domains_operations')->where('id',$debt->id)->first(),'Pending running state remains byte-for-byte unresolved');DB::table('domains_operations')->where('id',$debt->id)->update($running);
    commerceReject(fn()=>$d->update(['provenance'=>'adopted']));$d->refresh();
    commerceReject(fn()=>$d->update(['status'=>'active','billing_managed'=>false]));$d->refresh();
    same($opBefore,$d->operations()->orderBy('id')->get()->map(fn($o)=>$o->getRawOriginal())->all(),'Pending reads do not resolve old operations');
}
$retained=$d->getRawOriginal();DB::table('domains_domains')->where('id',$d->id)->update(['provider_expires_at'=>null,'renewal_policy'=>'unreadable']);$d->refresh();
Illuminate\Support\Facades\Auth::setUser($staff);$facts=DomainFacts::for($d);same('Unknown',$facts['Last recorded expiry (unverified)'],'Unreadable policy leaves staff expiry unknown');expect(isset($facts['Setup reason'],$facts['Open domain invoices']),'Unreadable policy still shows pending review facts');DB::table('domains_domains')->where('id',$d->id)->update($retained);
same($nativeBefore,$nativeMoney(),'Native invoices/items/transactions/credits preserved');
// A pending reservation continues to block checkout for the same canonical name.
commerceReject(fn()=>$new($d->domain));
Schema::table('domains_domains',fn($t)=>$t->dropColumn('provenance'));
try{HoldingProvenanceSchema::verify();throw new LogicException('Missing provenance accepted');}catch(RuntimeException $e){expect(str_contains($e->getMessage(),'migration required'),'Missing additive schema refuses');}
echo "Pending and purchased paired authority, reminder, dispatch, observer, presentation and retained schema checks passed.\n";$nativeSuccessful=true;
