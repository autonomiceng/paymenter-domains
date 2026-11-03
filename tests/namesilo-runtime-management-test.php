<?php
// Installed native authority through admitted transport to namespace-faked cURL.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{Environment,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{CorrectionWork,ManagementEvidence};
expect(method_exists(ManagementProtocol::class,'useReservation'),'Management needs its own pre-seal reservation scope');
$transferEnvironment=Environment::Live;
require __DIR__.'/Support/management-namesilo.php';require __DIR__.'/Support/namesilo-write-wire.php';
\Paymenter\Extensions\Servers\Domains\Services\LiveReservationSchema::install();
$legacyTransferExchange=$exchange;$exchange=static fn($origin,$cmd,$p,$ms)=>$legacyTransferExchange(str_replace('https://www.namesilo.com','https://ote.namesilo.com',$origin),$cmd,$p,$ms);
$money=static function():array {return array_map(static fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),['invoices','invoice_items','invoice_transactions','credits','jobs','failed_jobs']);};
$physicalWrites=static fn()=>array_values(array_filter($GLOBALS['nsPhysical'],static fn($c)=>AdmittedTransport::feature($c)!=='catalogue'));
foreach(['unlock','nameservers','email','unsent','lost','cap','off','drift','interactive'] as $mode){
    [$d,,$original]=$holding('admitted-'.$mode);$ms->route='apibatch';$transport=nsWriteTransport($binding,$clock);
    $driver=new Management($binding,$transport->exchange());$driver->useReservation($transport->reservation);
    commerceReject(fn()=>$driver->useReservation($transport->reservation));
    $GLOBALS['nsReply']=static fn($c,$p)=>$managementExchange('https://ote.namesilo.com/apibatch',$c,$p,25000);
    $action=in_array($mode,['email','lost'],true)?'auth_code_email':($mode==='nameservers'?'nameservers':'lock');
    $payload=$action==='lock'?['locked'=>false]:($action==='nameservers'?['nameservers'=>['ns3.example.net','ns4.example.net']]:[]);
    $op=$management->request($user,$d->id,$action,$payload,$key(),$driver);$GLOBALS['nsOperation']=$op->id;
    foreach(['server_id','account_fingerprint'] as $field){
        $foreign=clone $op;$foreign->$field=$field==='server_id'?$op->server_id+1:str_repeat('a',64);$entered=false;
        commerceReject(fn()=>$driver->withinReservation($foreign,static function()use(&$entered){$entered=true;}));same(false,$entered,'Foreign '.$field.' cannot enter management reservation');
    }
    $before=nsUsage($binding);$financial=$money();
    if($mode==='unsent'){config(['domains.provider_send_guard'=>static function():void {
        if((\Paymenter\Extensions\Servers\Domains\Models\Operation::findOrFail($GLOBALS['nsOperation'])->attempt['seal']??null)==='sealed'){throw new \RuntimeException('Synthetic prewire refusal');}
    }]);}
    if($mode==='lost'){$ms->fault='lost';}
    if($mode==='cap'){config([$GLOBALS['nsAccountKey'].'.daily_call_cap'=>$before['used']+1]);}
    if($mode==='off'){config(['domains.namesilo_live.management'=>false]);}
    if($mode==='drift'){$ms->during=static fn()=>DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']);}
    $result=$management->run($op->id,$driver,$mode==='interactive'?RequestOrigin::Interactive:RequestOrigin::Background);
    same($financial,$money(),'Management preserves native finance and jobs');same($before['spend_micros'],nsUsage($binding)['spend_micros'],'Baseline management reserves zero spend');
    if(in_array($mode,['unlock','nameservers','email'],true)){
        same('succeeded',$result->state,'Exact fresh readback/ack completes admitted '.$mode.':'.$result->state.':'.$result->safe_code);
        same([match($mode){'unlock'=>'domainUnlock','nameservers'=>'changeNameServers','email'=>'retrieveAuthCode'}],$physicalWrites(),'Exactly one correctly mapped mutation');
        same($before['used']+3,nsUsage($binding)['used'],'Prepare, separately reserved mutation, and readback each counted once');
    }elseif($mode==='unsent'){
        same(['held','management_definitely_unsent'],[$result->state,$result->safe_code],'Unconsumed local grant retains strict unsent management evidence');
        same('sealed',$result->attempt['seal'],'Unsent management keeps seal');expect(ManagementEvidence::idle($result),'Proven unsent nonfinancial work cannot block renewal');same([],$physicalWrites(),'Local refusal has zero mutation');
        commerceReject(fn()=>$result->update(['attempt'=>array_diff_key($result->attempt,['management_unsent'=>true])]));$result->refresh();
    }elseif($mode==='lost'){
        same('uncertain',$result->state,'Consumed email with lost acknowledgement stays uncertain');same(['retrieveAuthCode'],$physicalWrites(),'One email request');
        expect(!ManagementEvidence::idle($result),'Lost reply is not an unsent peer');
        commerceReject(fn()=>$result->update(['state'=>'held','safe_code'=>'management_definitely_unsent','attempt'=>[...$result->attempt,'management_unsent'=>true]]));$result->refresh();
        $ms->fault='';Cache::flush();same('uncertain',$management->reconcile($result->id,$driver)->state,'Readback cannot invent an email acknowledgement');
    }else{same([],$physicalWrites(),'Cap/Off/current authority refuses mutation');expect(($result->attempt['seal']??'absent')==='absent','Refusal precedes seal');}
    $physical=$physicalWrites();$management->run($result->id,$driver,RequestOrigin::Background);same($physical,$physicalWrites(),'No duplicate mutation on later run');
}
// A funded acquisition correction receives its own durable operation and own grant.
[$d,,$original]=$holding('admitted-correction',false);$ms->route='apibatch';$ms->renew=true;$transport=nsWriteTransport($binding,$clock);
$driver=new Management($binding,$transport->exchange());$driver->useReservation($transport->reservation);
$GLOBALS['nsReply']=static fn($c,$p)=>$managementExchange('https://ote.namesilo.com/apibatch',$c,$p,25000);
$original->update(['policy'=>[...$original->policy,'reconciliation'=>['observed_corrections'=>['auto_renew']]]]);
$purchase=$original->fresh()->attempt;$purchaseCalls=$state->calls;$before=nsUsage($binding);$financial=$money();
$correction=CorrectionWork::produce($d,$management,$driver);same('auto_renew_off',$correction->action,'Producer creates separately authorized auto-renew work');
expect($correction->id!==$original->id,'Correction owns another ledger row');same($original->operation_key,$correction->policy['management']['correction'],'Exact funded parent authorizes correction');
$GLOBALS['nsOperation']=$correction->id;
same('succeeded',$management->run($correction->id,$driver,RequestOrigin::Background)->state,'Fresh false readback completes separate auto-renew operation');
same(['removeAutoRenewal'],$physicalWrites(),'Correction never reuses or repeats purchase');same($purchaseCalls,$state->calls,'Original transfer never replays');same($purchase,$original->fresh()->attempt,'Original purchase seal/evidence unchanged');
same($before['used']+3,nsUsage($binding)['used'],'Correction owns its call reservation');same($before['spend_micros'],nsUsage($binding)['spend_micros'],'Correction cannot reserve purchase spend');same($financial,$money(),'Correction never invoices or returns acquisition money');
$again=CorrectionWork::produce($d,$management,$driver);expect($again===null||$again->id!==$correction->id,'Completed auto-renew correction converges');
$sent=$physicalWrites();$management->run($correction->id,$driver,RequestOrigin::Background);same($sent,$physicalWrites(),'Completed correction has no mutation replay');
foreach(['parent-reversed','lost-correction'] as $case){
    [$d,,$original]=$holding($case,false);$ms->route='apibatch';$ms->renew=true;$transport=nsWriteTransport($binding,$clock);
    $driver=new Management($binding,$transport->exchange());$driver->useReservation($transport->reservation);
    $original->update(['policy'=>[...$original->policy,'reconciliation'=>['observed_corrections'=>['auto_renew']]]]);
    $correction=CorrectionWork::produce($d,$management,$driver);$GLOBALS['nsOperation']=$correction->id;
    if($case==='parent-reversed'){\App\Models\InvoiceTransaction::create(['invoice_id'=>$original->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']);}
    else{$ms->fault='lost';}
    $financial=$money();$result=$management->run($correction->id,$driver,RequestOrigin::Background);
    same($financial,$money(),'Correction preserves current parent finance');
    if($case==='parent-reversed'){
        same([],$physicalWrites(),'Reversed parent cannot authorize correction');expect(($result->attempt['seal']??'absent')==='absent','Invalid parent cannot seal correction');
    }else{
        same('uncertain',$result->state,'Lost correction remains retained');same($correction->id,CorrectionWork::produce($d,$management,$driver)->id,'Producer retains the uncertain correction identity');
        $management->run($correction->id,$driver,RequestOrigin::Background);same(['removeAutoRenewal'],$physicalWrites(),'Unknown correction cannot repeat its mutation');
    }
}
echo "Dormant admitted management and separately authorized auto-renew correction passed.\n";$nativeSuccessful=true;
