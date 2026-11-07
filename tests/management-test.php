<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementEvidence,WriteFeatures};
[$d,$driver,$original]=$holding(placeholderComFixtureName('manage-basic-placeholder.com'));
commerceReject(fn()=>$management->request(commerceUser(),$d->id,'lock',['locked'=>false],$key(),$driver));
commerceReject(fn()=>$submit($d,$driver,'auth_code_display'));same([],$state->calls,'Unsupported/stranger actions do no I/O');
$nonce=$key();$op=$management->request($user,$d->id,'lock',['locked'=>false],$nonce,$driver);
same($op->id,$management->request($user,$d->id,'lock',['locked'=>false],$nonce,$driver)->id,'Idempotent request');
commerceReject(fn()=>$management->request($user,$d->id,'lock',['locked'=>true],$nonce,$driver));
$off=new DomainManagement($bindings,$clock);same('queued',$off->run($op->id,$driver)->state,'Disabled writes leave virgin request queued');same([],$state->calls,'Default gate no provider I/O');
same('succeeded',$management->run($op->id,$driver)->state,'Lock fresh readback confirms');same(['GetDomainInfo','SetRegLock','GetDomainInfo','GetRegLock'],array_column($state->calls,0),'One lock write with owned readback');
$nativeBeforeReplay = nativeFinancialSnapshot($op);
$before=count($state->calls);$management->run($op->id,$driver);same($before,count($state->calls),'Completed write never replayed');same(true,ManagementEvidence::resolved($op->fresh(),$d),'Durable proof resolves management');
same($nativeBeforeReplay, nativeFinancialSnapshot($op), 'Replay preserves native/financial state');
commerceReject(fn()=>$op->fresh()->update(['policy'=>array_diff_key($op->fresh()->policy,['management'=>true])]));
foreach([array_diff_key($op->fresh()->attempt,['management_reads'=>true]),[...$op->fresh()->attempt,'management_reads'=>[]]] as $changed){
    commerceReject(fn()=>$op->fresh()->update(['attempt'=>$changed]));
}
$r=new ManagementRequest($driver->capabilities()->binding,'forged',$d->domain,'lock',['locked'=>true],'456');$ctx=new RequestContext($r->binding,RequestOrigin::Interactive,$clock->now()->modify('+100 seconds'),new RequestBudget(10),$clock);
commerceReject(fn()=>$driver->perform($r,$ctx,$management));same($before,count($state->calls),'Forged descriptor no wire authority');
Cache::flush();$ns=$submit($d,$driver,'nameservers',['nameservers'=>['NS3.Example.com.','ns4.example.com']]);same('succeeded',$management->run($ns->id,$driver)->state,'Canonical nameservers read back');
// A new-sales stop must leave the holder's renewal price and transfer-out tools usable.
$stopped=Paymenter\Extensions\Servers\Domains\Models\Tld::where(['server_id'=>$d->server_id,'tld'=>$d->tld])->sole();
$holderPrice=(new Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices))->renewal($d,'USD',$clock->now())->retail;
$acquisition=$stopped->only(['register_enabled','transfer_enabled']);$stopped->update(['register_enabled'=>false,'transfer_enabled'=>false]);
activeFixtureRepublish($native,$stopped->id,$clock->now());
same($holderPrice,(new Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices))->renewal($d,'USD',$clock->now())->retail,'New-sales stop keeps the holder renewal price');
Cache::flush();$email=$submit($d,$driver,'auth_code_email');same('auth_email_accepted',$management->run($email->id,$driver)->safe_code,'Email accepted distinct from delivery');commerceReject(fn()=>$submit($d,$driver,'auth_code_email'));
$stopped->update($acquisition);activeFixtureRepublish($native,$stopped->id,$clock->now());
[$d,$driver]=$holding(placeholderComFixtureName('manage-unknown-placeholder.com'));$state->fault='SetRegLock';$op=$submit($d,$driver,'lock',['locked'=>false]);same('uncertain',$management->run($op->id,$driver)->state,'Malformed write and unmatched readback uncertain');$nativeBeforeReplay=nativeFinancialSnapshot($op);
$calls=count($state->calls);$management->run($op->id,$driver);same($calls,count($state->calls),'Unknown send never replayed');
same($nativeBeforeReplay,nativeFinancialSnapshot($op),'Unknown management replay preserves native/financial state');
$state->fault='';$state->locked=false;same('succeeded',$management->reconcile($op->id,$driver)->state,'Explicit read-only reconciliation confirms');same(true,$op->fresh()->attempt['management_reads'][1]['confirmed'],'Reconcile appends confirming readback');same(1,count(array_filter($state->calls,fn($v)=>$v[0]==='SetRegLock')),'Reconciliation never repeats write');
[$d,$driver]=$holding(placeholderComFixtureName('manage-foreign-placeholder.com'));$state->fault='owner';$op=$submit($d,$driver,'lock',['locked'=>false]);same('held',$management->run($op->id,$driver)->state,'Foreign provider owner refuses send');same(['GetDomainInfo'],array_column($state->calls,0),'Private/write followups blocked');
[$d,$driver]=$holding(placeholderComFixtureName('manage-permission-placeholder.com'));$role=Role::create(['name'=>'Manage staff','permissions'=>['admin.domains.manage']]);$staff=commerceUser();$staff->update(['role_id'=>$role->id]);$op=$management->request($staff,$d->id,'lock',['locked'=>false],$key(),$driver,'staff_correction');$role->update(['permissions'=>[]]);same('queued',$management->run($op->id,$driver)->state,'Fresh permission revocation refuses claim');same([],$state->calls,'Revoked staff no I/O');
[$d,$driver]=$holding(placeholderComFixtureName('manage-race-placeholder.com'));$op=$submit($d,$driver,'lock',['locked'=>false]);$state->during=static fn()=> $d->service->update(['status'=>'suspended']);same('held',$management->run($op->id,$driver)->state,'Native drift during preflight refuses seal');same(['GetDomainInfo'],array_column($state->calls,0),'Changed authority no send');
$nativeSuccessful=true;echo "Native management scenario passed\n";
