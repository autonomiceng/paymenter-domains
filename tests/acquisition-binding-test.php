<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding='example.com';
require __DIR__.'/Support/registration-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionPort,CatalogueAcceptances,OperationClaims,Rdap,TransferPrecheck,WriteAcceptance,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Registration,Transfer};
$binding=CatalogueAcceptances::binding(Account::where('server_id',$tlds[0]->server_id)->sole());
$writes=0;$exchange=static function()use(&$writes):string{$writes++;return 'malformed';};
$features=new WriteFeatures(registration:true,transfer:true);$accepted=new WriteAcceptance($binding,true,true,25);
$ports=[
    'register'=>AcquisitionPort::registration($binding,$freshBindings,$clock,$features,$accepted,new Registration($binding,$exchange)),
    'transfer'=>AcquisitionPort::transfer($binding,$freshBindings,$clock,$features,$accepted,new Transfer($binding,$exchange),new TransferPrecheck(new Rdap(static fn()=>throw new RuntimeException('Synthetic offline RDAP')))),
];
$numeric=false;$wrongOperation=null;$wrongDomain=null;$seen=['operation'=>0,'domain'=>0];
// Keep numeric-string hydration active through every port, claim, seal and send read.
foreach(['operation'=>Operation::class,'domain'=>Domain::class] as $kind=>$model){
    $model::retrieved(static function($row)use($kind,&$numeric,&$wrongOperation,&$wrongDomain,&$seen):void{
        if(!$numeric){return;}
        $row->server_id=(string)(($kind==='operation'?$wrongOperation:$wrongDomain)??$row->server_id);
        expect(is_string($row->getAttributes()['server_id']),'Raw hydration retains a numeric string');$seen[$kind]++;
    });
}
$legacy=$new(reservedFixtureName('retained-binding.example.com'));$d=$legacy->domain;
$identity=\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($d);$identity['server_id']=(string)$binding->serverId;
$inventory=['identity'=>$identity,'families'=>['glue'=>['state'=>'partial','records'=>['ns.retained-binding.example.com'=>'8.8.8.8']]]];
$d->update(['observation'=>['record_hold'=>['identity'=>$identity],'record_failure'=>['identity'=>$identity],'records'=>$inventory]]);
$numeric=true;$retained=Domain::findOrFail($d->id);
expect(\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::held($retained),'Legacy numeric-string observation hold survives acquisition normalization');
expect(\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::stopped($retained),'Legacy record discovery remains stopped');
same($inventory,\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::current($retained),'Legacy inventory and partial glue history remain current');
same($identity,\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($retained),'Legacy primary-failure identity remains recognizable');
$retained->server_id=(string)($binding->serverId+999);
same(false,\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::held($retained),'Different legacy server never inherits a hold');
same(null,\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::current($retained),'Different legacy server never inherits inventory');$numeric=false;
foreach($ports as $action=>$port){
    $op=$new(reservedFixtureName($action.'-binding.example.com'),action:$action);
    $registration->query=OperationClaims::query($op,$op->domain);$before=$writes;$seen=['operation'=>0,'domain'=>0];
    Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$binding->fingerprint);$numeric=true;
    foreach([1,2] as $pass){
        $result=$port->execute((int)$op->id);
        same('uncertain',$result->state,'Matching numeric-string account reaches the wire');
        same('sealed',$result->attempt['seal']??null,'Enabled funded acquisition passes claim and preflight');
        same($before+1,$writes,'Repeated execution never replays the write');
    }
    expect($seen['operation']>2&&$seen['domain']>1,'Hydration covers downstream operation and domain reads');
    $wrongOperation=$binding->serverId+999;commerceReject(fn()=>$port->execute((int)$op->id));
    same($before+1,$writes,'Different operation account never sends');$numeric=false;$wrongOperation=null;
    $foreign=$new(reservedFixtureName($action.'-foreign-binding.example.com'),action:$action);
    Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$binding->fingerprint);$wrongDomain=$binding->serverId+999;$numeric=true;
    $result=$port->execute((int)$foreign->id);
    same('held',$result->state,'Different holding account refuses at the claim boundary');
    same('claim_authority_invalid',$result->safe_code,'Normalization preserves account ownership');
    same([],$result->attempt??[],'Wrong holding account never claims');same($before+1,$writes,'Wrong holding account never sends');
    $numeric=false;$wrongDomain=null;
}
$nativeSuccessful=true;echo "Acquisition numeric-string account bindings passed through claim and dispatch.\n";
