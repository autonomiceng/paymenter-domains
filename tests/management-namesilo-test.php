<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
// Real native DB facade is required by the public management port's outside-lock guard.
(static function (): void { require __DIR__.'/Support/demo-c-ds-management.php'; })();
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth,Cache};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, PrivacyMode, Support};
[$d,$driver,$original]=$holding('baseline');
staffPanel(); $viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
same(PrivacyMode::Free,$driver->capabilities()->privacy,'Free privacy declared');
$forms=new \Paymenter\Extensions\Servers\Domains\Services\ManagementForms($management,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts(fn()=>$driver));
$before=[$d->operations()->count(),$ms->calls];
commerceReject(fn()=>$forms->submit($user,$d,'privacy_enable',['privacy'=>'1'],$key()));
same($before,[$d->operations()->count(),$ms->calls],'Hidden free privacy cannot be enabled by posting the old form');
foreach (['dns','ds','auth_code_display'] as $cap) { same(Support::Unsupported,$driver->capabilities()->get($cap)->support,'Advanced tools unsupported'); }
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
// A real encrypted ledger and engine grant are required to prove unknown DS sends never replay.
require __DIR__.'/Support/demo-c-ds-durable.php';
$nativeSuccessful=true; echo "NameSilo management baseline passed\n";
