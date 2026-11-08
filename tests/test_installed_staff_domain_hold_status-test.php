<?php
// Real paid transfers and staff actions: a row fixture cannot prove the saved audit and rendered header agree.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;

$failures=[];
$check=static function(bool $passed,string $message)use(&$failures):void { if(!$passed){$failures[]=$message;} };
$staff->update(['first_name'=>'Alex','last_name'=>'Admin']);
$class=static fn(string $name)=>'contains(concat(" ",normalize-space(@class)," ")," '.$name.' ")';
$page=static function($op)use($staff,$class):array {
    $html=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$op->domain_id])->assertSuccessful()->html();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    $text=static fn($node)=>trim(preg_replace('/\s+/',' ',$node?->textContent??''));
    $head=$xpath->query('//*['.$class('domains-head-line').']')->item(0);
    $rail='//*['.$class('domains-rail').']';
    $entry=static fn(string $label)=>$xpath->query($rail.'//*['.$class('domains-trace-item').'][.//*['.$class('domains-trace-title').' and normalize-space(.)="'.$label.'"]]');
    return [$text($head),$text($entry('Request #'.$op->id.' Transfer')->item(0)),$entry,$text,$xpath];
};
foreach([false,true] as $ordersOn){
    DB::table('domains_accounts')->update(['processing_enabled'=>true]);
    $op=$new(placeholderComFixtureName('hold-status-'.($ordersOn?'running':'paused').'-placeholder.com'),action:'transfer');
    $money=$nativeMoney();
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])
        ->callAction('hold')->assertHasNoActionErrors()->assertNotified('Request on hold');
    [$head,$request]=$page($op);
    $check(str_contains($head,'On hold')&&str_contains($head,'put on hold by staff'),'Held transfer header names the staff hold');
    $check(str_contains($request,'On hold')&&str_contains($request,'Put on hold by Alex Admin'),'Held request activity names the staff hold');
    DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['processing_enabled'=>$ordersOn]);
    $clock->instant=$clock->now()->modify('+1 minute');
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])
        ->callAction('release')->assertHasNoActionErrors()->assertNotified('Hold released');
    same(['queued',null],[$op->fresh()->state,$op->fresh()->safe_code],'Release clears the hold independently of Orders');
    [$head,$request,$entry,$text]=$page($op);
    $check(!str_contains(strtolower($head.' '.$request),'put on hold by staff')&&!str_contains(strtolower($head.' '.$request),'put on hold by alex admin'),'Released header and request activity drop the old staff hold');
    if($ordersOn){
        $check(str_contains($head,'Pending transfer')&&str_contains($head,'Transfer in progress. Nothing to do yet.'),'Running orders leave the domain transfer in progress');
        $check(str_contains($request,'In progress'),'Running orders leave the request activity in progress');
    }else{
        $check(str_contains($head,'Pending transfer')&&str_contains($head,'Orders are off for this account.'),'Paused orders are the current header cause: '.$head);
        // slop-sweep: after "Orders are off for this account." the step says only "Turn them on in Registrars."
        $check(str_contains($head,'Turn them on in Registrars'),'Paused header names the next step');
        $check(str_contains($request,'Under review')&&str_contains($request,'Orders are off for this account.'),'Paused orders are the current request activity cause');
        $check(str_contains($request,'Turn them on in Registrars'),'Paused request activity names the next step');
    }
    same(1,$entry('Hold released')->length,'One release event appears in Activity');
    $released=$text($entry('Hold released')->item(0));
    $check(str_contains($released,'Request #'.$op->id.' Transfer'),'Hold released names its request');
    $check(str_contains($released,'Alex Admin'),'Hold released names its actor');
    // Demo #71 was held again after its release; Activity must show that newer action too.
    $clock->instant=$clock->now()->modify('+1 minute');
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])
        ->callAction('hold')->assertHasNoActionErrors()->assertNotified('Request on hold');
    [$head,$request,$entry,$text,$xpath]=$page($op);
    $check(str_contains($head,'put on hold by staff')&&str_contains($request,'Put on hold by Alex Admin'),'A new staff hold is the real current cause');
    $check($entry('Put on hold')->length===2,'Activity includes both staff holds');
    $latest=$xpath->query('//*['.$class('domains-rail').']//*['.$class('domains-trace-title').']')->item(0);
    $check($text($latest)==='Put on hold','The newer hold precedes the historical release');
    $check(str_contains($text($entry('Put on hold')->item(0)),'Request #'.$op->id.' Transfer'),'The new hold names the same request');
    same($money,$nativeMoney(),'Hold and release preserve money and queue rows');
}
// Same-second staff actions use their append order, including the Activity rail's seven-entry cutoff.
$clock->instant=$clock->now()->modify('+1 minute');
foreach(['release','hold','release','hold','release','hold','release','hold'] as $action){$run($op->fresh(),$action);}
$history=$op->fresh()->policy['staff_actions'];$expected=[];
foreach($history as $i=>$action){
    if(($action['phase']??null)==='completed'&&in_array($action['action']??null,['hold','release'],true)){
        $expected[]=$action['action'].'-'.$op->id.'-'.$i;
    }
}
$entries=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::entries($op->domain->fresh());
$keys=array_values(array_map(static fn($row)=>$row['__key'],array_filter($entries,static fn($row)=>is_array($row)&&str_starts_with($row['__key']??'','hold-')||is_array($row)&&str_starts_with($row['__key']??'','release-'))));
same(array_reverse($expected),$keys,'Same-second actions remain newest first before the rail cutoff');
[$head,$request,$entry,$text,$xpath]=$page($op);
$latest=$xpath->query('//*['.$class('domains-rail').']//*['.$class('domains-trace-title').']')->item(0);
same('Put on hold',$text($latest),'The final same-second hold leads the rendered Activity rail');
// A queued paid renewal needs the same Orders-on instruction in its header and Activity.
[$renewal,$renewalDomain]=$makeRenewal(placeholderComFixtureName('orders-renewal-placeholder.com'));
DB::table('domains_operations')->where('id',$renewal->id)->update(['state'=>'queued']);
foreach([false,true] as $ordersOn){
    DB::table('domains_accounts')->where('server_id',$renewal->server_id)->update(['processing_enabled'=>$ordersOn]);
    [$head]=$page($renewal);
    same(!$ordersOn,str_contains($head,'Turn them on in Registrars.'),'Queued renewal header matches the account Orders switch: '.$head);
}
if($failures){fwrite(STDERR,implode("\n",$failures)."\n");}
same([],$failures,'Domain header and Activity reflect the current hold');
echo "Staff domain Hold/Release: paused and running orders, named release and later hold passed.\n";
$nativeSuccessful=true;
