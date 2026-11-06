<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native owner renewal preference scenarios.
declare(strict_types=1);
require_once __DIR__.'/Support/scenario-inventory.php';
$scenarios=new NativeScenarioInventory(['hard:staff','hard:cancellation','lifecycle:status','lifecycle:service','lifecycle:expiry','lifecycle:managed','lifecycle:pending','staff-stop','staff-resume','save-veto','audit-veto','audit-exception','audit-recovery']);
// Every hard-stop and rollback scenario uses Enom; avoid enrolling an unused second registrar.
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
    $html=$page($held);expect(!str_contains($html,'form-auto-renew')&&!str_contains($html,'Turn off auto-renew'),'Hard stop hides switch HTML: '.$stop);
    expect(preg_match('~<dt>Renewal invoices .*?</dt><dd><span class="domains-card__v">Off</span> <span class="domains-row__a domains-card__a"><a class="domains-a" href="[^"]+">Contact support</a></span></dd>~s',$html)===1&&str_contains($html,'<span class="domains-sub">Cancels '),'Hard stop shows Off with Contact support and the cancel date: '.$stop);
    $cancel?->delete();$scenarios->completed('hard:'.$stop);
}
// All switch lifecycle refusals are checked at the write, independently of presentation.
[$held]=$holder('owner-lifecycle-placeholder.com');$policy=$held->renewal_policy;
foreach(['status','service','expiry','managed','pending'] as $case){
    DB::table('domains_domains')->where('id',$held->id)->update(['provenance'=>$case==='pending'?'adoption_pending':'purchased','status'=>$case==='status'?'redemption':'active','provider_expires_at'=>$case==='expiry'?null:'2027-10-18','billing_managed'=>$case!=='managed']);
    $held->service->update(['status'=>$case==='service'?'cancelled':'active']);
    expect(!OwnerAutoRenew::available(RenewalStop::read($held->fresh())),'Unavailable switch: '.$case);
    commerceReject(fn()=>$preference->set($user,$held->id,false));$scenarios->completed('lifecycle:'.$case);
}
DB::table('domains_domains')->where('id',$held->id)->update(['provenance'=>'purchased','billing_managed'=>true,'provider_expires_at'=>'2027-10-18']);$held->service->update(['status'=>'active']);
[$second]=$holder('owner-other-key-placeholder.com');
// Staff can add a hard stop while the owner's reversible switch is Off.
$staffActor=staffUser([Staff::VIEW,'admin.domains.manage','admin.domains.holdings.stop','admin.domains.holdings.resume']);Auth::login($staffActor);
$preference->set($user,$second->id,false);
$actions=\Paymenter\Extensions\Servers\Domains\Admin\Support\HoldingActions::make($second->fresh());
$byName=[];foreach($actions as $action){$byName[$action->getName()]=$action;}
expect($byName['stop']->isVisible()&&!$byName['resume']->isVisible(),'Staff can hard-stop an owner-Off holding');
$l=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($second));
$ticket=\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::ticket($staffActor->id,$second->id,'stop',\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::stamp($l),$clock);
(new \Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview($clock))->run($staffActor,$second->id,'stop',$ticket,'Stop renewal after ownership review');
same('staff',$owners->present($second->fresh())->renewalOff,'Staff stop overrides owner Off');
commerceReject(fn()=>$preference->set($user,$second->id,true));commerceReject(fn()=>$billing->invoice($second->id,$user));$scenarios->completed('staff-stop');
$l=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($second));
$ticket=\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::ticket($staffActor->id,$second->id,'resume',\Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview::stamp($l),$clock);
(new \Paymenter\Extensions\Servers\Domains\Services\StaffHoldingReview($clock))->run($staffActor,$second->id,'resume',$ticket,'Resume after ownership review');
same('owner',$owners->present($second->fresh())->renewalOff,'Staff resume retains owner Off');$scenarios->completed('staff-resume');
// A rejected domain save never creates a false audit entry.
[$saveDomain]=$holder('owner-save-failure-placeholder.com');$saveVeto=true;
\Paymenter\Extensions\Servers\Domains\Models\Domain::updating(static function($row)use(&$saveVeto,$saveDomain){if($row->id===$saveDomain->id&&$saveVeto){return false;}});
$beforeAudit=WatchAudit::where('event','owner_auto_renew')->count();
commerceReject(fn()=>$preference->set($user,$saveDomain->id,false));
same(null,$saveDomain->fresh()->renewal_policy['owner_auto_renew']??null,'Domain veto leaves preference unchanged');
same($beforeAudit,WatchAudit::where('event','owner_auto_renew')->count(),'Rejected save creates no audit');$scenarios->completed('save-veto');$saveVeto=false;
$preference->set($user,$saveDomain->id,false);
// Audit failure rolls back the preference. Repeated attempts need no permanent marker.
[$auditDomain]=$holder('owner-audit-failure-placeholder.com');$veto=true;$auditThrows=false;
WatchAudit::creating(static function($row)use(&$veto,&$auditThrows){if($row->event==='owner_auto_renew'){if($auditThrows){throw new RuntimeException('Synthetic audit storage unavailable');}if($veto){return false;}}});
commerceReject(fn()=>$preference->set($user,$auditDomain->id,false));
same(null,$auditDomain->fresh()->renewal_policy['owner_auto_renew']??null,'Audit veto rolls back preference');$scenarios->completed('audit-veto');$veto=false;
$auditThrows=true;$session->forget('errors');$controller->save($req($auditDomain,['enabled'=>'0','confirmed'=>'1']),$auditDomain->id);
expect($session->get('errors')?->has('auto-renew')===true,'Audit exception returns safe HTTP error');
same(null,$auditDomain->fresh()->renewal_policy['owner_auto_renew']??null,'Audit exception rolls back preference');$scenarios->completed('audit-exception');$auditThrows=false;
$preference->set($user,$auditDomain->id,false);same(false,$auditDomain->fresh()->renewal_policy['owner_auto_renew'],'Retry succeeds after audit storage recovers');
$raw=DB::table('domains_watch_audit')->where('event','owner_auto_renew')->first()->data;expect(!str_contains($raw,'enabled'),'Audit details encrypted');$scenarios->completed('audit-recovery');
$scenarios->finish();
echo "Owner renewal hard stops and save rollback passed.\n";$nativeSuccessful=true;
