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
    $check(str_contains($head,'On hold')&&!str_contains($head,'Under review')&&str_contains($head,'put on hold by Alex Admin'),'Held transfer header reads one word for the hold and names who held it: '.$head);
    $check(str_contains($request,'On hold')&&!str_contains($request,'Under review')&&str_contains($request,'Put on hold by Alex Admin'),'Held request activity reads the same word and names who held it: '.$request);
    // U-E (J6 request #71): before staff press Release hold, the request notice says the paid transfer goes to the registrar.
    $notice=preg_replace('/\s+/',' ',html_entity_decode(strip_tags(Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->html()),ENT_QUOTES));
    $check((bool)preg_match('/Release hold (sends the test transfer to|queues the test transfer to send to) Enom\./',$notice),'Held transfer notice says what Release hold starts: '.$notice);
    DB::table('domains_accounts')->where('server_id',$op->server_id)->update(['processing_enabled'=>$ordersOn]);
    $clock->instant=$clock->now()->modify('+1 minute');
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])
        ->callAction('release')->assertHasNoActionErrors()->assertNotified('Hold released');
    same(['queued',null],[$op->fresh()->state,$op->fresh()->safe_code],'Release clears the hold independently of Orders');
    [$head,$request,$entry,$text,$xpath]=$page($op);
    $check(!str_contains(strtolower($head.' '.$request),'put on hold by'),'Released header and request activity drop the old staff hold');
    if($ordersOn){
        $check(str_contains($head,'Pending transfer')&&str_contains($head,'Transfer in progress. Nothing to do yet.'),'Running orders leave the domain transfer in progress');
        $check(str_contains($request,'In progress'),'Running orders leave the request activity in progress');
    }else{
        $check(str_contains($head,'Pending transfer')&&str_contains($head,'Orders are off for this account.'),'Paused orders are the current header cause: '.$head);
        // slop-sweep: after "Orders are off for this account." the step says only "Turn them on in Registrars."
        $check(str_contains($head,'Turn them on in Registrars')&&!str_contains($head,'Check request #'),'Paused header names one next step: '.$head);
        // The header says the cause once; the request's Activity entry keeps its status.
        $check(str_contains($request,'Under review')&&!str_contains($request,'Orders are off for this account.'),'Paused request activity does not repeat the header: '.$request);
        $page36=$text($xpath->query('//body')->item(0));
        // The Registrar row names the account, and leaves Orders off to the header.
        $check(substr_count($page36,'Orders are off for this account.')===1&&!str_contains(strtolower($page36),'orders paused'),'Orders are off is said once on the page, in one term: '.$page36);
        // Not active: no Sync now button and nothing confirmed yet.
        $check(!str_contains($page36,'Sync this domain')&&!str_contains($page36,'Use Sync now'),'No row asks for a sync the page cannot start');
        $check(!str_contains($page36,'Last confirmed data is retained'),'Nothing confirmed means nothing retained');
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
    $check(str_contains($head,'put on hold by Alex Admin')&&str_contains($request,'Put on hold by Alex Admin'),'A new staff hold is the real current cause');
    $check($entry('Put on hold')->length===2,'Activity includes both staff holds');
    $latest=$xpath->query('//*['.$class('domains-rail').']//*['.$class('domains-trace-title').']')->item(0);
    $check($text($latest)==='Put on hold','The newer hold precedes the historical release');
    $check(str_contains($text($entry('Put on hold')->item(0)),'Request #'.$op->id.' Transfer'),'The new hold names the same request');
    same($money,$nativeMoney(),'Hold and release preserve money and queue rows');
}
if($failures){fwrite(STDERR,implode("\n",$failures)."\n");}
same([],$failures,'Domain header and Activity reflect the current hold');
echo "Staff domain Hold/Release: paused and running orders, named release and later hold passed.\n";
$nativeSuccessful=true;
