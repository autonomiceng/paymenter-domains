<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, PrivacyMode, Support};
[$d,$driver,$original]=$holding('baseline');
staffPanel(); $viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
same(PrivacyMode::Free,$driver->capabilities()->privacy,'Free privacy declared');
foreach (['dns','ds','glue','auth_code_display'] as $cap) { same(Support::Unsupported,$driver->capabilities()->get($cap)->support,'Advanced tools unsupported'); }
foreach ([['nameservers',['nameservers'=>['NS3.EXAMPLE.NET.','ns4.example.net']],'changeNameServers'],['lock',['locked'=>false],'domainUnlock'],
    ['lock',['locked'=>true],'domainLock'],['privacy_enable',[],'addPrivacy'],['privacy_disable',[],'removePrivacy'],['auto_renew_off',[],'removeAutoRenewal'],['auth_code_email',[],'retrieveAuthCode']] as [$action,$payload,$cmd]) {
    Cache::flush(); $ms->calls=[]; $op=$submit($d,$driver,$action,$payload);
    same('succeeded',$management->run($op->id,$driver)->state,'Readback confirms '.$action);
    same([$cmd],array_column($writes(),0),'Exactly one mapped write');
    same(null,$op->invoice_id,'Free baseline has no invoice');
    if (in_array($action,['privacy_enable','privacy_disable'],true)) {
        same($action==='privacy_enable',$owners->present($d->fresh())->management['privacy']['state'],'Customer reads confirmed NameSilo privacy');
        same($action==='privacy_enable'?'On':'Off',DomainFacts::for($d->fresh())['WHOIS privacy']['value'],'Staff reads confirmed NameSilo privacy');
    }
    $nativeBeforeReplay = nativeFinancialSnapshot($op);
    $before=$ms->calls; $management->run($op->id,$driver); same($before,$ms->calls,'Completed operation never replays');
    same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
}
same(['email_accepted'=>true],$op->fresh()->attempt['management_ack'],'Email acceptance only');
try { $submit($d,$driver,'auth_code_email'); throw new LogicException('Missing cooldown'); } catch (DomainException) {}
$before=Invoice::count(); $calls=$ms->calls;
foreach (['privacy_purchase','privacy_renew','dns','ds_add','glue_add','auth_code_display'] as $action) {
    try { $submit($d,$driver,$action); throw new LogicException('Unsupported request admitted'); } catch (DomainException) {}
}
foreach (['glue_add','glue_update'] as $action) {
    try { $submit($d,$driver,$action,['host'=>'ns1.'.$d->domain,'ip'=>'2606:4700:4700::1111']); throw new LogicException('IPv6 glue admitted'); } catch (DomainException) {}
}
same($before,Invoice::count(),'Unsupported paid actions never invoice'); same($calls,$ms->calls,'Unsupported actions never perform I/O');
foreach (['127.0.0.1','ns1.123','-bad.example.net'] as $host) {
    try { $driver->validate('nameservers',['nameservers'=>[$host,'ns2.example.net']],$d->domain); throw new LogicException('Bad host accepted'); } catch (DomainException) {}
}
[$d,$driver]=$holding('unknown'); $ms->fault='lost'; $op=$submit($d,$driver,'lock',['locked'=>false]);
same('uncertain',$management->run($op->id,$driver)->state,'Lost response stays uncertain'); same(1,count($writes()),'One uncertain write');
$nativeBeforeReplay = nativeFinancialSnapshot($op);
$management->run($op->id,$driver); same(1,count($writes()),'Unknown write never replayed');
same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
$ms->fault=''; $ms->route='apibatch'; same('succeeded',$management->reconcile($op->id,$driver)->state,'Read-only recovery'); same(1,count($writes()),'Recovery emits no write');
[$d,$driver]=$holding('email-unknown'); $ms->fault='lost'; $op=$submit($d,$driver,'auth_code_email');
same('uncertain',$management->run($op->id,$driver)->state,'Lost email acknowledgement stays unknown');
$ms->fault=''; $ms->route='apibatch'; same('uncertain',$management->reconcile($op->id,$driver)->state,'Readback cannot invent original email acknowledgement');
same(['retrieveAuthCode'],array_column($writes(),0),'Email never resent by observation');
[$d,$driver]=$holding('slot-race'); $op=$submit($d,$driver,'auto_renew_off');
$ms->during=fn()=>Cache::put('domains-write-slot:'.$op->account_fingerprint,true,25);
same('queued',$management->run($op->id,$driver)->state,'Consume slot race defers before first send');
same('management_deferred',$op->fresh()->safe_code,'Bounded retryable deferral'); same('absent',$op->fresh()->attempt['seal'],'Deferred operation never sealed'); same([],$writes(),'Consume race emits no write');
Cache::flush(); same('succeeded',$management->run($op->id,$driver)->state,'Unsent deferral retries safely');
same(['removeAutoRenewal'],array_column($writes(),0),'Retry spends only one actual write grant');
[$d,$driver]=$holding('native-race'); $op=$submit($d,$driver,'lock',['locked'=>false]);
$ms->during=fn()=>$d->service->update(['status'=>'suspended']);
same('held',$management->run($op->id,$driver)->state,'Fresh native lifecycle drift refuses seal'); same([],$writes(),'Native drift emits no write');
foreach (['owner','large','getDomainInfo'] as $fault) {
    [$d,$driver]=$holding('refuse-'.$fault); $ms->fault=$fault; $op=$submit($d,$driver,'lock',['locked'=>false]);
    same('held',$management->run($op->id,$driver)->state,'Invalid read refuses before seal'); same([],$writes(),'No write after invalid ownership/read');
}
[$d,$driver]=$holding('background'); $ms->route='apibatch'; $op=$submit($d,$driver,'auto_renew_off');
same('succeeded',$management->run($op->id,$driver,RequestOrigin::Background)->state,'Background batch route');
$r=new ManagementRequest($binding,'probe',$d->domain,'lock',['locked'=>true]);
foreach ([new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('-1 second'),new RequestBudget(10),$clock),
    new RequestContext($binding,RequestOrigin::Interactive,$clock->now()->modify('+1 minute'),new RequestBudget(0),$clock)] as $ctx) {
    $count=count($ms->calls); try { $driver->prepare($r,$ctx); throw new LogicException('Unbounded read'); } catch (RuntimeException) {}
    same($count,count($ms->calls),'Expired or exhausted context performs no I/O');
}
$nativeSuccessful=true; echo "NameSilo management baseline passed\n";
