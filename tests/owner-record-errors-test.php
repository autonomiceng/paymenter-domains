<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Fixed field errors, no retained private input.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
use Paymenter\Extensions\Servers\Domains\Models\Operation;
[$d,$driver]=$holding(placeholderComFixtureName('record-errors-placeholder.com'));storefrontViews();
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$observer=new \Paymenter\Extensions\Servers\Domains\Services\DomainObservation($readBindings,$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock),$ports);
$observer->run($d->id);
foreach(['dns'=>['records'=>[['name'=>'@','type'=>'A','address'=>'8.8.8.8']],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest([])],
    'srv'=>['records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>0,'port'=>443,'target'=>'sip.example.com.']],'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest([],'srv')],
    'glue_add'=>['host'=>'ns1.record-errors-placeholder.com','ip'=>'8.8.8.8']]as$action=>$payload){Illuminate\Support\Facades\Cache::flush();same('succeeded',$management->run($submit($d,$driver,$action,$payload)->id,$driver)->state,'Proven editable state');}
Illuminate\Support\Facades\Cache::flush();same('succeeded',$management->run($submit($d,$driver,'glue_add',['host'=>'ns2.record-errors-placeholder.com','ip'=>'8.8.8.8'])->id,$driver)->state,'Second known host');
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$observer=new \Paymenter\Extensions\Servers\Domains\Services\DomainObservation($readBindings,$clock,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock),$ports);
$observer->run($d->id);
foreach(['dns'=>['records'=>[['name'=>'bad host','type'=>'A','address'=>'secret-invalid-value']],'preimage_digest'=>str_repeat('a',64)],
    'srv'=>['records'=>[['service'=>'_sip','protocol'=>'wrong','priority'=>'999999','weight'=>'-1','port'=>'bad','target'=>'no-dot']],'preimage_digest'=>str_repeat('a',64)],
    'ds_add'=>['ds'=>['key_tag'=>'65536','algorithm'=>'99','digest_type'=>'9','digest'=>'secret-invalid-value']],
    'ds_delete'=>['ds'=>[]], 'glue_add'=>['host'=>'foreign.example.com','ip'=>'127.0.0.1'],
    'glue_update'=>['host'=>'ns1.record-errors-placeholder.com','ip'=>'::ffff:8.8.8.8'], 'glue_delete'=>['host'=>'foreign.example.com'],
    'nameservers'=>['nameservers'=>implode("\n",array_map(fn($i)=>'ns'.$i.'.example.com',range(1,13)))]]as$action=>$payload){
    $payload+=['transfer_code'=>'private-extra-not-for-html','contact'=>['first_name'=>'private-extra-not-for-html'],'profile_id'=>'private-extra-not-for-html'];
    $before=Operation::count();$r=$post($action,$payload);$nonce=$r->request->get('_request_key');$response=$controller->manage($r,$d->id,$action);same($before,Operation::count(),'Invalid '.$action.' queues nothing');
    expect(!str_contains(serialize($session->all()),'secret-invalid-value'),'Submitted values never flashed');
    if($action==='nameservers'){
        $errors=$session->get('errors');app('view')->share('errors',$errors);
        $html=(new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($r,$d->id)->render();
    }else{same(422,$response->getStatusCode(),'Direct record retry has 422 status');$html=$response->getContent();}
    $dom=storefrontDom($html);$invalid=$dom->query('//*[@aria-invalid="true"]');
    if(!in_array($action,['ds_delete','glue_delete'],true)){expect($invalid->length>0,'Actual response attaches field errors for '.$action);}
    expect(str_contains($html,in_array($action,['ds_delete','glue_delete'],true)?'Reload the page and try again.':'Check the highlighted fields below.'),'Approved error summary is present');
    expect(!str_contains($html,'private-extra-not-for-html')&&!str_contains(serialize($session->all()),'private-extra-not-for-html'),'Private request extras are never retained');
    if($action!=='nameservers'){expect(!str_contains($html,'value="'.$nonce.'"'),'Direct retry issues fresh intent keys');}
    foreach($invalid as$field){expect($field->hasAttribute('aria-describedby'),'Field error is described');}
    if($action==='dns'){expect(str_contains($html,'value="secret-invalid-value"'),'Public DNS draft is retained only in direct escaped response');}
    $fieldMessages=match($action) {
        'dns'=>['name'=>'Enter @, *, or a hostname such as www.','address'=>'Enter an IPv4 address, such as 192.0.2.1.'],
        'srv'=>['protocol'=>'Choose TCP or UDP.','priority'=>'Enter a number from 0 to 65535.','weight'=>'Enter a number from 0 to 65535.','port'=>'Enter a number from 0 to 65535.','target'=>'Enter a hostname ending in a dot, such as sip.example.com.'],
        default=>[],
    };
    foreach($fieldMessages as$field=>$message){
        $input=$dom->query('//*[@id="'.$action.'-0-'.$field.'"][@aria-invalid="true"]');same(1,$input->length,'Expected invalid '.$action.' '.$field);
        $descriptions=[];foreach(explode(' ',$input->item(0)->getAttribute('aria-describedby'))as$id){foreach($dom->query('//*[@id="'.$id.'"]')as$node){$descriptions[]=trim($node->textContent);}}
        expect(in_array($message,$descriptions,true),'Exact error describes '.$action.' '.$field);
    }
    if($action==='glue_update'){same(1,$invalid->length,'Only the submitted host IP is invalid');same('glue-0-ip',$invalid->item(0)->getAttribute('id'),'Error targets matching host');same(['edit-glue'],array_map(fn($n)=>$n->getAttribute('id'),iterator_to_array($dom->query('//dialog[contains(@class,"domains-edit")][@open]'))),'Only submitted editor opens');}
    if($action==='ds_add'){expect(str_contains($html,'value="secret-invalid-value"'),'Public DS draft retained');}

}
foreach ([['records'=>[]],['records'=>[null]],['records'=>[['name'=>'@','type'=>'A','address'=>'8.8.8.8']],'preimage_digest'=>'bad']] as $index=>$payload) {
    // JSON represents null rows and empty arrays that form encoding cannot express.
    $r=$post('dns',$payload+['preimage_digest'=>str_repeat('a',64)],true);$response=$controller->manage($r,$d->id,'dns');same(422,$response->getStatusCode(),'List error is a direct retry');
    expect(str_contains($response->getContent(),['Keep between 1 and 50 records.','Check record 1. These values don’t work together.','We couldn’t save this change. Reload the page and try again.'][$index]),'Cause-specific list error is visible');
}
foreach (['dns'=>['name'=>'@','type'=>'A','address'=>'8.8.8.8'],'srv'=>['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>0,'port'=>443,'target'=>'sip.example.com.']] as $action=>$row) {
    foreach (['foo',2] as $key) {
        $before=Operation::count();$response=$controller->manage($post($action,['records'=>[$key=>$row],'preimage_digest'=>str_repeat('a',64)]),$d->id,$action);
        same(422,$response->getStatusCode(),'Non-list '.$action.' rows produce a rendered retry');same($before,Operation::count(),'Malformed list queues nothing');
        $cache=$response->headers->get('Cache-Control');expect(str_contains($cache,'no-store')&&str_contains($cache,'private'),'Malformed-list retry is private and not stored');
        expect(str_contains($response->getContent(),'Keep between 1 and 50 records.'),'Malformed-list error is visible');
    }
}
// Native ownership drift is refused before every advanced request; a submitted ID never authorizes.
$before=Operation::count();Illuminate\Support\Facades\DB::table('services')->where('id',$d->service_id)->update(['user_id'=>commerceUser()->id]);
foreach(['dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete']as$action){try{$controller->manage($post($action),$d->id,$action);expect(false,'Cross-owner refused');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}}
same($before,Operation::count(),'No foreign operation');
same(32,\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::limits('namesilo')['first_name'],'Shared field limits use the engine schema');
$nativeSuccessful=true;echo "Native record field errors, security boundaries, non-flashing values and ownership checks passed.\n";
