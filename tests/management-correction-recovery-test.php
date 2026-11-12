<?php
// Enom 444f31c management-actions/request-gates; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/management.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Services\{CorrectionWork,DomainManagement,EnomTestManagement,EnomTestSendLimitSchema,WriteFeatures};
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
EnomTestSendLimitSchema::install();
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.management'=>true]);
$management=new DomainManagement($bindings,$clock,new WriteFeatures(management:true),true,testManagement:true);
// Frozen corrections have a deterministic producer and exact readback, including provider auto renew off.
[$d,$driver,$original]=$holding(placeholderComFixtureName('manage-auto-correct-placeholder.com'));
$state->renew=true;$original->update(['policy'=>[...$original->policy,'reconciliation'=>[...($original->policy['reconciliation']??[]),'observed_corrections'=>['auto_renew']]]]);
$b=$driver->capabilities()->binding;
$setting=$product->server->settings()->firstOrNew(['key'=>EnomTestManagement::KEY]);
// The pre-upgrade receipt is frozen independently of the release's command list.
$legacy=['fingerprint'=>$b->fingerprint,'route'=>'https://resellertest.enom.com/interface.asp',
    'commands'=>['ModifyNS','SetRegLock','Contacts','SynchAuthInfo','SetHosts','SetDomainSRVHosts','AddDnsSec','DeleteDnsSec'],'interval'=>25];
$setting->encrypted=true;$setting->type='string';$setting->value=json_encode($legacy);$setting->save();

expect(EnomTestManagement::accepted($b),'Correction uses accepted deployment management policy');
$before=$nativeMoney();
commerceReject(fn()=>$management->request($user,$d->id,'auto_renew_off',[],hash('sha256','manual-before-correction'),$driver));
commerceReject(fn()=>$management->request($user,$d->id,'auto_renew_off',[],hash('sha256','forged-before-correction'),$driver,correction:'missing-original'));
$bindings->setProcessing($b->serverId,false);
commerceReject(fn()=>$management->request($user,$d->id,'auto_renew_off',[],hash('sha256','paused-correction'),$driver,correction:$original->operation_key));
$bindings->setProcessing($b->serverId,true,static fn()=>null);
// Inject stale/corrupt retained state below model guards to exercise dispatch authority itself.
$generation=$original->authority_generation;DB::table('domains_operations')->where('id',$original->id)->update(['authority_generation'=>$generation+1]);
commerceReject(fn()=>$management->request($user,$d->id,'auto_renew_off',[],hash('sha256','foreign-generation'),$driver,correction:$original->operation_key));
DB::table('domains_operations')->where('id',$original->id)->update(['authority_generation'=>$generation]);
$funding=$original->getRawOriginal('funding');$original->funding=[...$original->funding,'refund'=>['status'=>'credited']];
DB::table('domains_operations')->where('id',$original->id)->update(['funding'=>$original->getAttributes()['funding']]);
commerceReject(fn()=>$management->request($user,$d->id,'auto_renew_off',[],hash('sha256','refunded-correction'),$driver,correction:$original->operation_key));
DB::table('domains_operations')->where('id',$original->id)->update(['funding'=>$funding]);$original->refresh();
// No upgrade write to the stored consent is needed, and revoked consent still defers work.
$setting->value='null';$setting->save();same(null,CorrectionWork::produce($d,$management,$driver),'Missing consent defers the correction');
same([],$state->calls,'Refused corrections make no provider calls');
$setting->value=json_encode($legacy);$setting->save();
$receipt=$setting->getRawOriginal('value');
$acquisition=DB::table('domains_operations')->where('id',$original->id)->first();
$correction=CorrectionWork::produce($d,$management,$driver);
expect($correction!==null,'Accepted management creates the frozen automatic renewal correction');
$state->fault='SetRenew';
same('uncertain',$management->run($correction->id,$driver)->state,'Lost correction result stays uncertain');
for($round=0;$round<3;$round++){
    Cache::flush();$again=CorrectionWork::produce($d,$management,$driver);
    same($correction->id,$again?->id,'Producer retains the same uncertain correction');
    same('uncertain',$management->run($again->id,$driver)->state,'Retry cannot repeat an uncertain correction write');
}
$state->fault='';$state->renew=false;
same('succeeded',$management->reconcile($correction->id,$driver)->state,'Read-only confirmation resolves the original correction');
same(null,CorrectionWork::produce($d,$management,$driver),'Confirmed corrections converge without another request');
same(1,count(array_filter($state->calls,fn($call)=>$call[0]==='SetRenew')),'Lost reply and repeated workers send SetRenew only once');
same($before,$nativeMoney(),'Ancillary correction preserves native financial rows');
same(json_encode($acquisition),json_encode(DB::table('domains_operations')->where('id',$original->id)->first()),'Ancillary correction preserves registration authority and evidence');
commerceReject(fn()=>$management->request($user,$d->id,'auto_renew_off',[],hash('sha256','manual-auto-renew'),$driver));
commerceReject(fn()=>$management->request($user,$d->id,'auto_renew_off',[],hash('sha256','forged-auto-renew'),$driver,correction:'missing-original'));
same($receipt,$setting->fresh()->getRawOriginal('value'),'Upgrade and worker leave the encrypted legacy receipt untouched');
$nativeSuccessful=true;echo "Automatic renewal correction producer and exact readback passed.\n";
