<?php
// Installed native runtime with synthetic account-bound ports. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
(new \Paymenter\Extensions\Servers\Domains\Domains)->installed();
$fixtureActivation=static fn()=>null;
require __DIR__.'/owner-records.php';
require __DIR__.'/installed.php';
require_once __DIR__.'/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,RuntimePorts,NativeCatalogue,PriceCatalogue,SellerPolicies,RenewalAuthority,RenewalAcceptance,RenewalAdmission,RenewalExecution,RenewalWriter,RenewalCompletion,WriteFeatures,CatalogueAcceptances};
use Paymenter\Extensions\Servers\Domains\Models\Account;
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
foreach([AccountBindings::class=>$readBindings,NativeCatalogue::class=>$native,PriceCatalogue::class=>$prices,SellerPolicies::class=>$sellers] as $type=>$value){app()->instance($type,$value);}
installedPanel();
[$d,$driver,$original]=$holding('record-runtime.com');
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$a=Account::where('server_id',$d->server_id)->sole();$b=CatalogueAcceptances::binding($a);
$nativePage=$page;$page=static function()use($nativePage):string { storefrontViews();app('view')->getFinder()->flush();return $nativePage(); };
$runtimePorts=app(RuntimePorts::class);$compositionReady=true;
$registerRecords=static function()use($runtimePorts,$b,$bindings,$clock,$engine,$reconcile,$management,&$driver,&$compositionReady):void {
    $features=new WriteFeatures(true,true,true,true);$authority=new RenewalAuthority($bindings,$clock);$accept=new RenewalAcceptance($b,true,true,25);
    $renewal=new RenewalExecution($bindings,$authority,new RenewalAdmission($authority,$bindings,$accept,$clock),$accept,$features,$clock);
    $composition=['acquisition'=>$engine,'reconciliation'=>$reconcile,'renewal'=>$renewal,
        'renewal_writer'=>new RenewalWriter($b,$features,$accept,fn()=>$renewal,static fn()=>throw new LogicException('No renewal transport')),
        'renewal_completion'=>new RenewalCompletion($bindings,$clock),'management'=>$management,'management_protocol'=>$driver];
    $runtimePorts->register($b->fingerprint,static function()use($composition,&$compositionReady){return $compositionReady?$composition:[];});
};
$dueRecords=static function()use($clock,$d):void {
    $clock->instant=$clock->now()->modify('+61 seconds');
    Illuminate\Support\Facades\DB::table('domains_observation_queues')->where('server_id',$d->server_id)->update(['turn'=>-1]);
    Illuminate\Support\Facades\DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['due_at'=>$clock->now()->getTimestamp(),'safe_code'=>null]);
};
