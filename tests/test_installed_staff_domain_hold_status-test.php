<?php
// Real paid transfers and staff actions: a row fixture cannot prove the saved audit and rendered header agree.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Presentation\State;

$failures=[];
$check=static function(bool $passed,string $message)use(&$failures):void { if(!$passed){$failures[]=$message;} };
$staff->update(['first_name'=>'Alex','last_name'=>'Admin']);
$class=static fn(string $name)=>'contains(concat(" ",normalize-space(@class)," ")," '.$name.' ")';
$page=static function($op)use($staff,$class):array {
    $html=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$op->domain_id])->assertSuccessful()->html();
    $dom=new DOMDocument;@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
    $text=static fn($node)=>trim(preg_replace('/\s+/',' ',$node?->textContent??''));
    // The head says the domain's own state; the status step is the notice that follows it.
    $head=$text($xpath->query('//*[@data-piece="head-line"]')->item(0)).' '.panelNoticesText($html);
    $entry=static fn(string $label)=>$xpath->query('//*[@data-piece="trace"]/li[.//*['.$class('domains-panel-step-t').' and normalize-space(.)="'.$label.'"]]');
    return [$head,$text($entry('Request #'.$op->id.' Transfer')->item(0)),$entry,$text,$xpath];
};
foreach([false,true] as $ordersOn){
    DB::table('domains_accounts')->update(['processing_enabled'=>true]);
    $op=$new(placeholderComFixtureName('hold-status-'.($ordersOn?'running':'paused').'-placeholder.com'),action:'transfer');
    $money=$nativeMoney();
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])
        ->callAction('hold')->assertHasNoActionErrors()->assertNotified('Request on hold');
    [$head,$request]=$page($op);
    $check(str_contains($head,State::UnderReview->label())&&str_contains($head,'put on hold by staff'),'Held transfer header names the staff hold');
    $check(str_contains($request,State::UnderReview->label())&&str_contains($request,'Put on hold by Alex Admin'),'Held request activity names the staff hold');
    DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['processing_enabled'=>$ordersOn]);
    $clock->instant=$clock->now()->modify('+1 minute');
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->assertActionEnabled('release')
        ->callAction('release')->assertHasNoActionErrors()->assertNotified('Hold released');
    same(['queued',null],[$op->fresh()->state,$op->fresh()->safe_code],'Release clears the hold with Orders on or off');
    same($ordersOn,(bool)\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole()->processing_enabled,'Release preserves the Orders switch');
    same(0,$state->writes,'Hold and release do not send purchases');
    [$head,$request,$entry,$text,$xpath]=$page($op);
    $check(!str_contains(strtolower($head.' '.$request),'put on hold by staff')&&!str_contains(strtolower($head.' '.$request),'put on hold by alex admin'),'Released header and request activity drop the old staff hold');
    if($ordersOn){
        $check(str_contains($head,'Pending transfer')&&str_contains($head,'Transfer in progress. Nothing to do yet.'),'Running orders leave the domain transfer in progress');
        $check(str_contains($request,State::Queued->label())||str_contains($request,State::Checking->label()),'Running orders leave the request activity in progress');
    }else{
        $check(str_contains($head,'Pending transfer')&&str_contains($head,'Orders are off for this account.'),'Paused orders are the current header cause: '.$head);
        // slop-sweep: after "Orders are off for this account." the step says only "Turn them on in Registrars."
        $check(str_contains($head,'Turn them on in Registrars')&&!str_contains($head,'Check request #'),'Paused header names one next step: '.$head);
        // The header says the cause once; the request's Activity entry keeps its status.
        $check(str_contains($request,'Under review')&&!str_contains($request,'Orders are off for this account.'),'Paused request activity does not repeat the header: '.$request);
        // The Registrar row names the account, and leaves Orders off to the header.
        $whole=$text($xpath->query('//body')->item(0));
        $check(substr_count($whole,'Orders are off for this account.')===1&&!str_contains(strtolower($whole),'orders paused'),'Orders are off is said once on the page, in one term: '.$whole);
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
    $latest=$xpath->query('//*[@data-piece="trace"]//*['.$class('domains-panel-step-t').']')->item(0);
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
$latest=$xpath->query('//*[@data-piece="trace"]//*['.$class('domains-panel-step-t').']')->item(0);
same('Put on hold',$text($latest),'The final same-second hold leads the rendered Activity rail');
// An Orders hold can be released before staff turn Orders on; it only queues the paid request.
DB::table('domains_accounts')->update(['processing_enabled'=>true]);
$paused=$new(placeholderComFixtureName('orders-held-release-placeholder.com'),action:'transfer');
$paused->update(['state'=>'held','safe_code'=>'processing']);
DB::table('domains_accounts')->where('server_id',$paused->server_id)->update(['processing_enabled'=>false]);
$money=$nativeMoney();$attempt=$paused->attempt;
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$paused->id])->assertActionVisible('release')->assertActionEnabled('release')
    ->callAction('release')->assertHasNoActionErrors()->assertNotified('Hold released');
same(['queued',null],[$paused->fresh()->state,$paused->fresh()->safe_code],'An Orders hold releases into the queue while Orders is off');
same([$money,$attempt,0],[$nativeMoney(),$paused->fresh()->attempt,$state->writes],'Orders hold release preserves money and no-send proof');
// A queued paid renewal needs the same Orders-on instruction in its header and Activity.
DB::table('domains_accounts')->update(['processing_enabled'=>true]);
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
