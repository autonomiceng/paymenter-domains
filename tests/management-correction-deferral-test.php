<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/management.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\{Operation,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\{CorrectionWork,SiteDnsDefault};

$profile=DB::transaction(fn()=>WatchProfile::create(['name'=>'Correction DNS','nameservers'=>['ns1.example.com','ns2.example.com'],
    'website_endpoints'=>[],'email_endpoints'=>[],'enabled'=>true,'edited_by'=>$user->id,'reason'=>'Synthetic registration default']));
fixtureBusinessChoice(SiteDnsDefault::KEY,(string)$profile->id,'string');
[$d,$driver,$original]=$holding(placeholderComFixtureName('correction-prior-renewal-placeholder.com'));
// A sent renewal can legitimately coexist with an unstarted ancillary registration correction.
$renewal=$original->replicate(['invoice_item_id']);
$renewal->fill(['operation_key'=>'renew:correction-blocker','action'=>'renew','state'=>'uncertain',
    'safe_code'=>'renewal_confirmation_required','completed_at'=>null,'confirmed_expires_at'=>null,
    'attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent'],'policy'=>[]]);
$id=DB::table('domains_operations')->insertGetId($renewal->getAttributes());
$peer=Operation::findOrFail($id);
$rows=DB::table('domains_operations')->where('domain_id',$d->id)->orderBy('id')->get()->toJson();
$money=nativeFinancialSnapshot($original);
for($round=0;$round<3;$round++){
    Cache::flush();
    same(null,CorrectionWork::produce($d,$management,$driver),'A correction waits for the unresolved sent renewal without failing its job');
}
same($rows,DB::table('domains_operations')->where('domain_id',$d->id)->orderBy('id')->get()->toJson(),'Deferral preserves all original and uncertain request evidence');
same($money,nativeFinancialSnapshot($original),'Deferral preserves invoices, transactions, credits and authority');
same([],$state->calls,'Deferral makes no provider read or write');
commerceReject(fn()=>$management->request($user,$d->id,'nameservers',['nameservers'=>$original->payload['nameservers']],
    hash('sha256','direct-correction-blocked'),$driver,correction:$original->operation_key));
same('uncertain',$peer->fresh()->state,'Direct requests also preserve the unresolved renewal');
// A refused local acceptance check also defers the scheduled correction instead of failing its job.
DB::table('domains_operations')->where('id',$peer->id)->delete();
$count=DB::table('domains_operations')->where('domain_id',$d->id)->count();
$checks=0;
$refusing=new Paymenter\Extensions\Servers\Domains\Services\DomainManagement($bindings,$clock,new Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(management:true),true,25,false,static function()use(&$checks):bool{$checks++;return false;});
for($round=1;$round<=2;$round++){
    Cache::flush();same(null,CorrectionWork::produce($d,$refusing,$driver),'A correction waits while local management acceptance is refused');
    same($round,$checks,'Each attempt reaches the local acceptance check');
}
same($count,DB::table('domains_operations')->where('domain_id',$d->id)->count(),'Refused acceptance creates no correction request');
same([],$state->calls,'Refused acceptance makes no provider read or write');
$nativeSuccessful=true;echo "Registration correction defers behind uncertain renewal without replay.\n";
