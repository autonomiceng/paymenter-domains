<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native fixtures and synthetic registrar only.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Invoice,ServiceCancellation};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,OwnerAutoRenewController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,OwnerAutoRenew,RenewalStop,RuntimeIneligible,RuntimeReason};
\Paymenter\Extensions\Servers\Domains\Services\HoldingProvenanceSchema::install();
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
$preference=new OwnerAutoRenew($clock);$controller=new OwnerAutoRenewController($owners,$preference);
$session=app('request')->session();$other=commerceUser();
$holder=static function(string $name)use($prepare,$reconcile){[$op,$binding]=$prepare($name);same('succeeded',$reconcile->run($op->id)->state,'Confirmed registration');return [$op->domain()->firstOrFail(),$binding];};
[$d,$binding]=$holder('owner-switch-placeholder.com');$server=$product->server;
foreach(['domains_manual_renewals_enabled','domains_billing_enabled'] as $key){$server->settings()->updateOrCreate(['key'=>$key],['value'=>true,'type'=>'boolean']);}
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$req=static function($domain,array $data,$actor=null,string $intent='auto-renew')use($session,$user):Request {
    $r=Request::create('/domains/'.$domain->id.'/auto-renew','POST',$data);$r->setUserResolver(fn()=>$actor??$user);$r->setLaravelSession($session);
    $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$domain->id.':'.$intent));return $r;
};
$save=static function($domain,bool $enabled)use($controller,$req,$session):void {
    $session->forget('errors');$response=$controller->save($req($domain,['enabled'=>$enabled?'1':'0','confirmed'=>'1']),$domain->id);
    same(route('domains.domains.show',$domain->id),$response->getTargetUrl(),'Preference returns to domain');
    same(null,$session->get('errors'),'Preference succeeds');
};
$page=static function($domain)use($owners,$req):string {storefrontViews();return (new OwnedDomainController($owners))->show($req($domain,[]),$domain->id)->render();};
$audit=static fn()=>WatchAudit::where('subject_type','domain')->where('subject_id',$d->id)->where('event','owner_auto_renew')->count();
$dto=$owners->present($d->fresh());same(['On',null,true],[$dto->renewal,$dto->renewalOff,$dto->autoRenew['enabled']??null],'Legacy absence means On');
same([],$owners->present($d->fresh(),false)->autoRenew,'Read-only presentation hides switch');
$save($d,true);same(0,$audit(),'Legacy On is idempotent without a write');
$html=$page($d);foreach(['Turn off auto-renew','Turn off auto-renew?','Oct 18, 2027','will expire on this date unless you renew it.','name="confirmed"','name="_token"'] as $text){expect(str_contains($html,$text),'Switch renders '.$text);}
$stale=$req($d,['enabled'=>'0','confirmed'=>'1']);$controller->save($stale,$d->id);
try{$controller->save($stale,$d->id);expect(false,'Replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Consumed key rejected');}
$save($d,false);same(1,$audit(),'Repeated Off adds no audit');
$dto=$owners->present($d->fresh());same(['Off','owner',false],[$dto->renewal,$dto->renewalOff,$dto->autoRenew['enabled']??null],'Owner Off is distinct and reversible');
expect(isset($dto->renewNow['url']),'Owner Off still offers manual renewal');
$html=$page($d);expect(str_contains($html,'Auto-renew is off. This domain expires on <time datetime="2027-10-18">Oct 18, 2027</time>. Renew it now or turn auto-renew back on.'),'Exact owner notice');
expect(str_contains($html,'Turn on auto-renew'),'Off shows On switch');
// The owner notice offers renewal through the day before expiry.
$clock->instant=new DateTimeImmutable('2027-10-17T12:00:00Z');
expect(isset($owners->present($d->fresh())->renewNow['url']),'Owner Off offers renewal one day before expiry');
expect(str_contains($page($d),'Renew it now or turn auto-renew back on.'),'Late owner notice offers renewal');
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');$late=$page($d);
expect(!isset($owners->present($d->fresh())->renewNow['url']),'Cutoff hides manual renewal');
expect(str_contains($late,'Auto-renew is off. This domain expires on <time datetime="2027-10-18">Oct 18, 2027</time>. Contact support if you want to keep it.'),'Owner notice after cutoff');
expect(!str_contains($late,'Renew it now'),'No renewal promise after cutoff');
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$summary=(new DomainSummaries)->one($user,'managed',$d->id);expect($summary->renewalOff,'List summary also shows Off');
$before=Invoice::count();same(null,$billing->invoice($d->id),'Off skips scheduled invoice');same($before,Invoice::count(),'Off creates no invoice');
// Refuse another owner both at the HTTP boundary and inside the locked writer.
try{$controller->save($req($d,['enabled'=>'1'],$other),$d->id);expect(false,'Foreign HTTP owner refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
commerceReject(fn()=>$preference->set($other,$d->id,true));same(false,$d->fresh()->renewal_policy['owner_auto_renew'],'Foreign actor changes nothing');
try{$controller->save($req($d,['enabled'=>'1'],null,'renew'),$d->id);expect(false,'Wrong intent refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Intent bound to action');}
[$second]=$holder('owner-other-key-placeholder.com');
try{$controller->save($req($second,['enabled'=>'1']),$d->id);expect(false,'Other domain key refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Intent bound to domain');}
foreach([[],['enabled'=>'false'],['enabled'=>['1']]] as $bad){
    $session->forget('errors');$controller->save($req($d,$bad),$d->id);expect($session->get('errors')?->has('auto-renew')===true,'Invalid preference refused');
}
foreach([['enabled'=>'0'],['enabled'=>'0','confirmed'=>'0']] as $bad){
    $session->forget(['errors','domains_confirm']);$response=$controller->save($req($d,$bad),$d->id);
    same('auto-renew',$response->getSession()->get('domains_confirm'),'Turning off without the confirm step asks for it inline');
}
$session->forget('domains_confirm');
same(1,$audit(),'Invalid requests add no audit');
$save($d,true);$save($d,true);same(2,$audit(),'Repeated On adds no audit');
$save($d,false);
// A real native payment and synthetic registrar completion retain Off across the next cycle.
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
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
$save($d,true);$next=$billing->invoice($d->id);expect($next->id!==$invoice->id,'On inside next billing window issues next debt');
same($next->id,$billing->invoice($d->id)->id,'Scheduled retry reuses next debt');
// Hard stops retain their current authority and hide the switch, including when owner Off is also set.
foreach(['staff','cancellation'] as $stop){
    [$held]=$holder(placeholderComFixtureName('owner-hard-'.$stop.'-placeholder.com'));$policy=$held->renewal_policy;
    $held->update(['renewal_policy'=>[...$policy,'owner_auto_renew'=>false,...($stop==='staff'?['renewals_stopped'=>true]:[])]]);
    $cancel=$stop==='cancellation'?ServiceCancellation::create(['service_id'=>$held->service_id,'type'=>'end_of_period','reason'=>'Synthetic']):null;
    $held=$held->fresh();$l=RenewalStop::read($held);
    expect(RenewalStop::blocked($l),'Hard stop blocks manual authority: '.$stop);
    same($stop,RenewalStop::offReason($l),'Hard stop takes precedence over owner: '.$stop);
    $dto=$owners->present($held);same([$stop,[],[]],[$dto->renewalOff,$dto->autoRenew,$dto->renewNow],'Hard stop hides switch and manual form: '.$stop);
    commerceReject(fn()=>$preference->set($user,$held->id,true));
    commerceReject(fn()=>$billing->invoice($held->id,$user));
    $session->forget('errors');$controller->save($req($held,['enabled'=>'1']),$held->id);
    expect($session->get('errors')?->has('auto-renew')===true,'Hard stop returns safe HTTP error');
    same(false,$held->fresh()->renewal_policy['owner_auto_renew'],'Refused HTTP toggle changes nothing');
    $html=$page($held);expect(!str_contains($html,'Turn on auto-renew')&&!str_contains($html,'Turn off auto-renew'),'Hard stop hides switch HTML: '.$stop);
    expect(str_contains($html,$stop==='cancellation'?'This domain is set to cancel when it expires on':'Renewals are paused for this domain. Contact support to keep it.'),'Hard stop explains support action');
    $cancel?->delete();
}
// All switch lifecycle refusals are checked at the write, independently of presentation.
[$held]=$holder('owner-lifecycle-placeholder.com');$policy=$held->renewal_policy;
foreach(['status','service','expiry','managed','pending'] as $case){
    DB::table('domains_domains')->where('id',$held->id)->update(['provenance'=>$case==='pending'?'adoption_pending':'purchased','status'=>$case==='status'?'redemption':'active','provider_expires_at'=>$case==='expiry'?null:'2027-10-18','billing_managed'=>$case!=='managed']);
    $held->service->update(['status'=>$case==='service'?'cancelled':'active']);
    expect(!OwnerAutoRenew::available(RenewalStop::read($held->fresh())),'Unavailable switch: '.$case);
    commerceReject(fn()=>$preference->set($user,$held->id,false));
}
DB::table('domains_domains')->where('id',$held->id)->update(['provenance'=>'purchased','billing_managed'=>true,'provider_expires_at'=>'2027-10-18']);$held->service->update(['status'=>'active']);
// Scheduled billing keeps its due-date cutoff; manual requests remain available until expiry.
foreach(['2027-09-26','2027-09-27','2027-10-10','2027-10-11','2027-10-17','2027-10-18','2027-10-19'] as $day){
    [$calendar]=$holder(placeholderComFixtureName('owner-calendar-'.str_replace('-','',$day).'-placeholder.com'));$clock->instant=new DateTimeImmutable($day.'T12:00:00Z');
    $save($calendar,false);same(null,$billing->invoice($calendar->id),'Off skips at '.$day);$save($calendar,true);
    if($day>='2027-09-27'&&$day<'2027-10-11'){expect($billing->invoice($calendar->id)!==null,'On issues inside window '.$day);}
    else{try{$billing->invoice($calendar->id);expect(false,'Outside calendar refused');}catch(RuntimeIneligible $e){same(RuntimeReason::OutsideCalendar,$e->reason,'Exact calendar refusal '.$day);}}
    if($day>='2027-10-18'){same([],$owners->present($calendar->fresh())->renewNow,'Expired holding has no new manual request');}
    elseif($day>='2027-10-11'){same(range(1,9),$owners->present($calendar->fresh())->renewNow['terms']??null,'After due the same multi-year terms are offered');}
}
staffPanel();Auth::login(staffUser([Staff::VIEW]));$save($d,false);
$facts=DomainFacts::for($d->fresh());same('Auto-renew off by owner. Issued invoices stay payable.',$facts['Future renewals'],'Staff distinguishes owner Off');
expect(str_contains(implode(' ',$facts['Owner auto-renew changes']),'Customer #'.$user->id.' · Auto-renew off'),'Staff sees retained owner audit');
// Staff can add a hard stop while the owner's reversible switch is Off.
$staffActor=staffUser([Staff::VIEW,'admin.domains.manage','admin.domains.holdings.stop','admin.domains.holdings.resume']);Auth::login($staffActor);
$preference->set($user,$second->id,false);
$actions=\Paymenter\Extensions\Servers\Domains\Admin\Support\HoldingActions::make($second->fresh());
$byName=[];foreach($actions as $action){$byName[$action->getName()]=$action;}
expect($byName['stop']->isVisible()&&!$byName['resume']->isVisible(),'Staff can hard-stop an owner-Off holding');
$l=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($second));
$ticket=\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::ticket($staffActor->id,$second->id,'stop',\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::stamp($l),$clock);
(new \Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview($guard,$clock))->run($staffActor,$second->id,'stop',$ticket,'Stop renewal after ownership review');
same('staff',$owners->present($second->fresh())->renewalOff,'Staff stop overrides owner Off');
commerceReject(fn()=>$preference->set($user,$second->id,true));commerceReject(fn()=>$billing->invoice($second->id,$user));
$l=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($second));
$ticket=\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::ticket($staffActor->id,$second->id,'resume',\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::stamp($l),$clock);
(new \Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview($guard,$clock))->run($staffActor,$second->id,'resume',$ticket,'Resume after ownership review');
same('owner',$owners->present($second->fresh())->renewalOff,'Staff resume retains owner Off');
// A rejected domain save never creates a false audit entry.
[$saveDomain]=$holder('owner-save-failure-placeholder.com');$saveVeto=true;
\Paymenter\Extensions\Servers\Domains\Models\Domain::updating(static function($row)use(&$saveVeto,$saveDomain){if($row->id===$saveDomain->id&&$saveVeto){return false;}});
$beforeAudit=WatchAudit::where('event','owner_auto_renew')->count();
commerceReject(fn()=>$preference->set($user,$saveDomain->id,false));
same(null,$saveDomain->fresh()->renewal_policy['owner_auto_renew']??null,'Domain veto leaves preference unchanged');
same($beforeAudit,WatchAudit::where('event','owner_auto_renew')->count(),'Rejected save creates no audit');$saveVeto=false;
$preference->set($user,$saveDomain->id,false);
// Audit failure rolls back the preference. Repeated attempts need no permanent marker.
[$auditDomain]=$holder('owner-audit-failure-placeholder.com');$veto=true;$auditThrows=false;
WatchAudit::creating(static function($row)use(&$veto,&$auditThrows){if($row->event==='owner_auto_renew'){if($auditThrows){throw new RuntimeException('Synthetic audit storage unavailable');}if($veto){return false;}}});
commerceReject(fn()=>$preference->set($user,$auditDomain->id,false));
same(null,$auditDomain->fresh()->renewal_policy['owner_auto_renew']??null,'Audit veto rolls back preference');$veto=false;
$auditThrows=true;$session->forget('errors');$controller->save($req($auditDomain,['enabled'=>'0','confirmed'=>'1']),$auditDomain->id);
expect($session->get('errors')?->has('auto-renew')===true,'Audit exception returns safe HTTP error');
same(null,$auditDomain->fresh()->renewal_policy['owner_auto_renew']??null,'Audit exception rolls back preference');$auditThrows=false;
$preference->set($user,$auditDomain->id,false);same(false,$auditDomain->fresh()->renewal_policy['owner_auto_renew'],'Retry succeeds after audit storage recovers');
$raw=DB::table('domains_watch_audit')->where('event','owner_auto_renew')->first()->data;expect(!str_contains($raw,'enabled'),'Audit details encrypted');
DB::table('domains_watch_audit')->where('subject_type','domain')->where('subject_id',$d->id)->where('event','owner_auto_renew')->update(['data'=>'invalid ciphertext']);
Auth::login($staffActor);
same('Unavailable; review encryption access',DomainFacts::for($d->fresh())['Owner auto-renew changes'],'Unreadable audit does not break staff facts');
$native=\Livewire\Livewire::actingAs($staffActor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id]);
$native->assertSuccessful()->assertSee('Unavailable; review encryption access');
$route=app('router')->getRoutes()->getByName('domains.domains.auto-renew');
foreach(['web','auth','throttle:60,1,domains.owner',App\Http\Middleware\MustVerfiyEmail::class] as $middleware){expect(in_array($middleware,$route->gatherMiddleware(),true),'Preference route retains '.$middleware);}
same(10,$route->locksFor(),'Preference POST serializes session');
echo "Owner auto-renew toggles, locked ownership, hard stops, manual payment, later cycles and calendars passed.\n";$nativeSuccessful=true;
