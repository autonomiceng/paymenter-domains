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
$audit=static fn()=>WatchAudit::where('subject_type','domain')->where('subject_id',$d->id)->where('event','owner_auto_renew')->count();
$dto=$owners->present($d->fresh());same(['On',null,true],[$dto->renewal,$dto->renewalOff,$dto->autoRenew['enabled']??null],'Legacy absence means On');
// The Auto Pay line follows what renewal collection can do, not the saved id on the service.
$gateway=App\Models\Gateway::create(['name'=>'Saved method','extension'=>'AutoPayLine','type'=>'gateway','enabled'=>true]);
$card=App\Models\BillingAgreement::create(['user_id'=>$user->id,'gateway_id'=>$gateway->id,'name'=>'Saved card','external_reference'=>'synthetic-only']);
same(false,$owners->present($d->fresh())->autoPay,'No saved card, no Auto Pay');
$d->service->update(['billing_agreement_id'=>$card->id]);
same(true,$owners->present($d->fresh())->autoPay,'A saved card on a live gateway is charged');
App\Models\Gateway::whereKey($gateway->id)->update(['enabled'=>false]);same(false,$owners->present($d->fresh())->autoPay,'A disabled gateway cannot charge');App\Models\Gateway::whereKey($gateway->id)->update(['enabled'=>true]);
$card->update(['user_id'=>$other->id]);same(false,$owners->present($d->fresh())->autoPay,'Another user\'s card is never charged');$card->update(['user_id'=>$user->id]);
$card->delete();same(false,$owners->present($d->fresh())->autoPay,'A removed card is not charged, though the service keeps its id');
$d->service->update(['billing_agreement_id'=>null]);$card->forceDelete();App\Models\Gateway::whereKey($gateway->id)->delete();
same([],$owners->present($d->fresh(),false)->autoRenew,'Read-only presentation hides switch');
$save($d,true);same(0,$audit(),'Legacy On is idempotent without a write');
$html=$page($d);foreach(['form="form-auto-renew" class="domains-btn domains-btn--secondary domains-btn--sm">Turn off</button>','Turn off auto-renew?','>Turn off auto-renew<',$d->domain.' expires on Oct 18, 2027 unless you renew it.','name="confirmed"','name="_token"'] as $text){expect(str_contains($html,$text),'Switch renders '.$text);}
// Without a known expiry date the warning still says the domain expires unless renewed.
$undated=view('domains::partials.owner-auto-renew',['keys'=>['auto-renew'=>'key'],'domain'=>(object)['name'=>$d->domain,'expiry'=>null,'autoRenew'=>['url'=>'/auto-renew','enabled'=>true],'actions'=>['show'=>'/domain']]])->render();
expect(str_contains($undated,$d->domain.' expires unless you renew it.'),'Undated switch warns the domain expires');
$stale=$req($d,['enabled'=>'0','confirmed'=>'1']);$controller->save($stale,$d->id);
try{$controller->save($stale,$d->id);expect(false,'Replay refused');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(419,$e->getStatusCode(),'Consumed key rejected');}
$save($d,false);same(1,$audit(),'Repeated Off adds no audit');
$dto=$owners->present($d->fresh());same(['Off','owner',false],[$dto->renewal,$dto->renewalOff,$dto->autoRenew['enabled']??null],'Owner Off is distinct and reversible');
expect(isset($dto->renewNow['url']),'Owner Off still offers manual renewal');
$html=$page($d);expect(str_contains($html,'<span class="domains-sub">Cancels Oct 18, 2027</span>')&&str_contains($html,'>Renew now</a>')&&!str_contains($html,'Auto-renew is off'),'Off shows the cancel date and Renew now, with no notice');
expect(str_contains($html,'form="form-auto-renew" class="domains-btn domains-btn--secondary domains-btn--sm">Turn on</button>')&&!str_contains($html,'Turn off auto-renew?'),'Off shows On switch');
// The owner notice offers renewal through the day before expiry.
$clock->instant=new DateTimeImmutable('2027-10-17T12:00:00Z');
expect(isset($owners->present($d->fresh())->renewNow['url']),'Owner Off offers renewal one day before expiry');
expect(str_contains($page($d),'>Renew now</a>'),'Late owner page offers renewal');
$clock->instant=new DateTimeImmutable('2027-10-18T00:00:00Z');$late=$page($d);
expect(!isset($owners->present($d->fresh())->renewNow['url']),'Cutoff hides manual renewal');
expect(str_contains($late,'<time datetime="2027-10-18">Oct 18, 2027</time>')&&str_contains($late,'Cancels Oct 18, 2027'),'Owner page after cutoff shows the expiry and cancel date, got: '.substr($late,(int)strpos($late,'<dt>Expires'),600));
expect(!str_contains($late,'Renew now'),'No renewal promise after cutoff');
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
$route=app('router')->getRoutes()->getByName('domains.domains.auto-renew');
foreach(['web','auth','throttle:60,1,domains.owner',App\Http\Middleware\MustVerfiyEmail::class] as $middleware){expect(in_array($middleware,$route->gatherMiddleware(),true),'Preference route retains '.$middleware);}
same(10,$route->locksFor(),'Preference POST serializes session');
echo "Owner auto-renew preference and ownership passed.\n";$nativeSuccessful=true;
