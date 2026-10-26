<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema,ManagementPayload};
[$d,$driver]=$holding(placeholderComFixtureName('record-confirmation-placeholder.com'));storefrontViews();
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
ObservationSchema::install();$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports);
same('refreshed',$observer->run($d->id),'Complete initial record inventory');
// Rendered-script contract only. The offline runner does not execute browser events.
$script=$page();
expect(str_contains($script, "row.querySelectorAll('input:not([type=hidden]):not(:disabled), select:not(:disabled)')"),'Removal checks exclude disabled inputs and selects omitted from submission');
expect(str_contains($script, 'deleting(row) || !filled(row)'), 'Removed rows use the shared submitted-field predicate');
expect(str_contains($script, '!deleting(row) && filled(row)'), 'Retained rows use the same submitted-field predicate');
$cases=[
    'dns'=>[
        'records'=>[['name'=>'@','type'=>'TXT','address'=>'<original> & text'],['name'=>'www','type'=>'A','address'=>'8.8.8.8']],
        'cleared'=>['name'=>'','type'=>'','address'=>'','mx_pref'=>null,'_source'=>'0','host_id'=>99],
        'kept'=>['name'=>'0','type'=>'TXT','address'=>'   ','mx_pref'=>0],
        'description'=>'@ TXT <original> & text',
    ],
    'srv'=>[
        'records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>0,'port'=>443,'target'=>'sip.example.com.'],['service'=>'xmpp','protocol'=>'tcp','priority'=>5,'weight'=>5,'port'=>5222,'target'=>'chat.example.com.']],
        'cleared'=>['service'=>'','protocol'=>'','priority'=>null,'weight'=>'','port'=>'','target'=>'','_source'=>'0','host_id'=>31],
        'kept'=>['service'=>'xmpp','protocol'=>'tcp','priority'=>0,'weight'=>'0','port'=>5222,'target'=>'chat.example.com.','_source'=>'1','host_id'=>999],
        'description'=>'_sip._tcp sip.example.com. port 443',
    ],
];
foreach($cases as $action=>$case){
    Cache::flush();$controller->manage($post($action,['records'=>$case['records'],'preimage_digest'=>ManagementPayload::digest([],$action)]),$d->id,$action);
    $op=$d->operations()->orderByDesc('id')->firstOrFail();same('succeeded',$management->run($op->id,$driver)->state,'Two existing '.$action.' records');
    $section=$owners->present($d->fresh())->management[$action];
    // An untouched blank addition is not removal of an existing record.
    $before=Operation::count();$io=count($state->calls);
    $response=$controller->manage($post($action,['confirmed'=>'0','records'=>[...$case['records'],[]],'preimage_digest'=>$section['digest']]),$d->id,$action);
    same(302,$response->getStatusCode(),'A blank new '.$action.' row needs no removal confirmation');same($before+1,Operation::count(),'An ordinary replacement still queues');same($io,count($state->calls),'Blank-row POST does not dispatch');
    $op=$d->operations()->orderByDesc('id')->firstOrFail();Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Unchanged inventory with a blank addition completes');
    $section=$owners->present($d->fresh())->management[$action];
    // Zero and whitespace are submitted values. Incomplete rows need field errors, not deletion confirmation.
    foreach([0,'0','   '] as $value){
        $field=$value==='   '?($action==='dns'?'address':'target'):($action==='dns'?'mx_pref':'priority');
        $before=Operation::count();$io=count($state->calls);
        $response=$controller->manage($post($action,['confirmed'=>'0','records'=>[[$field=>$value],$case['kept'],[]],'preimage_digest'=>$section['digest']]),$d->id,$action);
        same(422,$response->getStatusCode(),'Incomplete nonempty '.$action.' row is refused locally');
        $dom=storefrontDom($response->getContent());
        expect($dom->query('//input[starts-with(@name,"records[0]")][@aria-invalid="true"]')->length>0,'Nonempty '.$action.' row reaches field validation');
        same(0,$dom->query('//form[contains(@action,"/manage/'.$action.'")]//dialog[@open]')->length,'Nonempty row is not a removal confirmation');
        same($before,Operation::count(),'Invalid nonempty row queues nothing');same($io,count($state->calls),'Invalid nonempty row makes no provider calls');
    }
    // Removing an existing row still needs confirmation when new rows keep or increase the total.
    foreach ([false,true] as $explicit) {
        foreach ([1,2] as $additions) {
            Cache::flush();
            $cleared=$case['cleared'];if($explicit){$cleared['_delete']='1';}
            $draft=[$cleared,$case['kept']];
            for($i=1;$i<=$additions;$i++){$draft[]=$action==='dns'?['name'=>'new'.$i,'type'=>'TXT','address'=>'new value '.$i]:['service'=>'new'.$i,'protocol'=>'tcp','priority'=>0,'weight'=>0,'port'=>443,'target'=>'new'.$i.'.example.com.'];}
            $before=Operation::count();$io=count($state->calls);
            $response=$controller->manage($post($action,['confirmed'=>'0','records'=>$draft,'preimage_digest'=>$section['digest']]),$d->id,$action);
            same(422,$response->getStatusCode(),'Existing '.$action.' removal confirms despite equal or increased count');
            expect(str_contains($response->headers->get('Cache-Control'),'no-store')&&str_contains($response->headers->get('Cache-Control'),'private'),'Balanced removal confirmation is private');
            $confirmation=storefrontDom($response->getContent());
            same([$case['description']],array_map(fn($n)=>trim($n->textContent),iterator_to_array($confirmation->query('//dialog[@open]//*[@data-confirm-slot="removed"]/span'))),'Only the original removed record appears');
            same($before,Operation::count(),'Balanced unconfirmed removal queues nothing');same($io,count($state->calls),'Balanced unconfirmed removal sends nothing');
            $response=$controller->manage($post($action,['records'=>$draft,'preimage_digest'=>$section['digest']]),$d->id,$action);
            same(302,$response->getStatusCode(),'Fresh confirmed balanced replacement queues');same($before+1,Operation::count(),'Fresh confirmation queues exactly once');same($io,count($state->calls),'Confirmed balanced POST does not dispatch');
            $op=$d->operations()->orderByDesc('id')->firstOrFail();same($additions+1,count($op->payload['records']),'Replacement retains the filled additions');
            $completed=$management->run($op->id,$driver);same('succeeded',$completed->state,'Balanced '.$action.' replacement completes: '.$completed->safe_code);
            Cache::flush();$changed=$owners->present($d->fresh())->management[$action];
            $beforeRestore=Operation::count();
            $response=$controller->manage($post($action,['confirmed'=>'1','records'=>$case['records'],'preimage_digest'=>$changed['digest']]),$d->id,$action);
            same(302,$response->getStatusCode(),'Confirmed inventory restore queues');same($beforeRestore+1,Operation::count(),'Restore creates a fresh operation');
            $restore=$d->operations()->orderByDesc('id')->firstOrFail();same('succeeded',$management->run($restore->id,$driver)->state,'Restore original inventory for the next independent case');
            $section=$owners->present($d->fresh())->management[$action];
        }
    }
    foreach([false,true] as $explicit){
        $cleared=$case['cleared'];if($explicit){$cleared['_delete']='1';}
        $draft=[$cleared,$case['kept'],['_source'=>'','host_id'=>123,'_delete'=>'']];
        $before=Operation::count();$io=count($state->calls);
        $response=$controller->manage($post($action,['confirmed'=>'0','records'=>$draft,'preimage_digest'=>$section['digest']]),$d->id,$action);
        same(422,$response->getStatusCode(),'Clearing an existing '.$action.' row requires confirmation');
        expect(str_contains($response->headers->get('Cache-Control'),'no-store')&&str_contains($response->headers->get('Cache-Control'),'private'),'Confirmation response stays private');
        if($action==='dns'){expect(str_contains($response->getContent(),'&lt;original&gt; &amp; text'),'Original TXT description is escaped');}
        same($before,Operation::count(),'Unconfirmed '.$action.' removal queues nothing');same($io,count($state->calls),'Confirmation performs no provider I/O');
        $dom=storefrontDom($response->getContent());$form='//form[contains(@action,"/manage/'.$action.'")]';$path=$form.'//dialog[@open]';
        same(1,$dom->query($path)->length,'One inline '.$action.' confirmation');
        same(['removed'],array_map(fn($n)=>$n->getAttribute('data-confirm-row'),iterator_to_array($dom->query($path.'//*[@data-confirm-row][not(@hidden)]'))),'Removed facts are visible');
        same([$case['description']],array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query($path.'//*[@data-confirm-slot="removed"]/span'))),'Cleared original is listed once; retained and blank new rows are absent');
        same(3,$dom->query($form.'//tbody/tr')->length,'Blank new row is retained in the draft');
        $valueField=$action==='dns'?'address':'weight';$expected=$action==='dns'?'   ':'0';
        same($expected,$dom->query($form.'//input[@name="records[1]['.$valueField.']"]')->item(0)?->getAttribute('value'),'Retained whitespace or zero survives the confirmation');
        if($explicit){same(1,$dom->query($form.'//input[@name="records[0][_delete]"][@value="1"]')->length,'Explicit delete survives exactly once');}
    }
    Cache::flush();$before=Operation::count();$io=count($state->calls);
    $response=$controller->manage($post($action,['records'=>$draft,'preimage_digest'=>$section['digest']]),$d->id,$action);
    same(302,$response->getStatusCode(),'Confirmed '.$action.' replacement is queued');same($before+1,Operation::count(),'Confirmed replacement queues once');same($io,count($state->calls),'Confirmed POST does not dispatch');
    $op=$d->operations()->orderByDesc('id')->firstOrFail();same(1,count($op->payload['records']),'Payload excludes the cleared row and blank addition');
    if($action==='dns'){same('   ',$op->payload['records'][0]['address'],'TXT whitespace is preserved in the actual payload');same('0',$op->payload['records'][0]['name'],'Zero host name is retained');}
    else{same(0,$op->payload['records'][0]['priority'],'Numeric zero priority is retained');same(0,$op->payload['records'][0]['weight'],'String zero weight is retained');expect(!isset($op->payload['records'][0]['host_id']),'Browser metadata grants no provider ID');}
    same('succeeded',$management->run($op->id,$driver)->state,'Confirmed '.$action.' deletion completes through synthetic readback');
}
$nativeSuccessful=true;echo "Owner DNS/SRV implicit and explicit removal, zero validation, TXT whitespace and confirmed payloads passed.\n";
