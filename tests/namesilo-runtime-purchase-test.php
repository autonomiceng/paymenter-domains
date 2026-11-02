<?php
// Synthetic installed engine-to-cURL contract. Profile creation remains a separate fake port.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,LiveReservations,OperationClaims,RefundProof};
expect(method_exists(\Paymenter\Extensions\Servers\Domains\Contracts\RegistrationProtocol::class,'useReservation'),'Genuine acquisition protocol must bind a pre-seal reservation');
(new Installation)->install();
$installedFixture=true;$fixtureEnvironment=Environment::Live;
config(['app.url'=>'https://billing.example.com']);
require __DIR__.'/Support/namesilo-registration-fixture.php';
app()->instance(Clock::class,$clock);
// These namespace functions replace every cURL call in AdmittedTransport; no socket is possible.
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options): bool {$GLOBALS['purchaseOptions']=array_replace($GLOBALS['purchaseOptions']??[],$options);return true;}
function curl_exec($h): bool {
    parse_str(parse_url($GLOBALS['purchaseOptions'][CURLOPT_URL],PHP_URL_QUERY),$p);
    $command=basename(parse_url($GLOBALS['purchaseOptions'][CURLOPT_URL],PHP_URL_PATH));
    unset($p['key'],$p['version'],$p['type']);
    $GLOBALS['physical'][]=$command;
    $body=($GLOBALS['purchaseReply'])($command,$p);
    return ($GLOBALS['purchaseOptions'][CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
function curl_getinfo($h,int $option): int {return 200;}
function curl_close($h): void {}
$account=Account::where('server_id',$binding->serverId)->sole();
$settings=['fingerprint'=>$binding->fingerprint,'daily_call_cap'=>1000,'daily_spend_limit_usd'=>'1000.00','currency'=>'USD','balance_funding'=>true];
$accountKey='domains.namesilo_live.accounts.'.$account->stable_identity;
config(['domains.namesilo_live.site'=>'https://billing.example.com',$accountKey=>$settings,
    'domains.namesilo_live.catalogue'=>true,'domains.namesilo_live.registration'=>true]);
$ledger=new LiveReservations(new NameSiloLiveReservationPolicy);
$prepare=static function(string $mode)use($binding,$makeEngine,$exchange,$registration,$clock,&$protocol,&$engine,$accountKey,$settings,&$profiles,&$acceptance,$freshBindings,$claims,$bindingFor): void {
    $registration->mode=$mode==='success'?'stillon':'300';$registration->calls=[];
    $GLOBALS['physical']=[];config([$accountKey=>$settings,'domains.provider_send_guard'=>null]);
    $transport=new AdmittedTransport($binding,static fn(bool $write)=>['api_key'=>'placeholder-api-key']);
    $wire=$transport->exchange();
    $GLOBALS['purchaseReply']=static function($command,$p)use($mode,$exchange,$registration,$binding,$transport):string {
        if($command==='registerDomain'){
            $op=Operation::where('operation_key',$GLOBALS['purchaseOperation'])->sole();
            same('sealed',$op->attempt['seal'],'Durable engine seal precedes physical handoff');
            expect(isset($op->funding['seal']),'Exact native funding sealed');
            same(false,$transport->reservation->unconsumed((int)$op->id),'Grant consumed immediately before physical send');
            same('https://www.namesilo.com/apibatch/registerDomain',strtok($GLOBALS['purchaseOptions'][CURLOPT_URL],'?'),'Fixed Live batch purchase route');
            foreach(['auto_renew','private','years'] as $key){if(isset($p[$key])){$p[$key]=(int)$p[$key];}}
            if($mode==='timeout'){throw new \RuntimeException('Synthetic lost response');}
        }
        return $exchange('https://ote.namesilo.com/apibatch',$command,$p,25000);
    };
    // Profile port exercises existing frozen-owner proof, without pretending it has NS4 durable authority.
    $profileExchange=static fn($origin,$command,$p,$timeout)=>$exchange('https://ote.namesilo.com/apibatch',$command,$p,$timeout);
    $makeEngine($binding,$profileExchange);
    // Rebuild the genuine protocol/bridge with the physical exchange, retaining the same preflight/profile policy.
    $protocol=new Registration($binding,$profiles,$wire);
    commerceReject(fn()=>$protocol->useReservation(new SendReservation($bindingFor(0),new NameSiloLiveReservationPolicy)));
    $protocol->useReservation($transport->reservation);
    commerceReject(fn()=>$protocol->useReservation($transport->reservation));
    $bridge=new Writer($binding,$features=new \Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(registration:true),$acceptance,static function()use(&$engine){return $engine;},$protocol);
    // Use the exact preflight policy objects required by DomainOperations::consume.
    $fresh=new \Paymenter\Extensions\Servers\Domains\Services\RegistrationPreflight($freshBindings,$features,$acceptance,$profiles);
    $engine=new \Paymenter\Extensions\Servers\Domains\Services\DomainOperations($claims,$fresh,$bridge,$clock);
    if($mode==='unsent'){
        config(['domains.provider_send_guard'=>static function()use($registration):void {
            $op=Operation::where('operation_key',$GLOBALS['purchaseOperation'])->sole();
            if(($op->attempt['seal']??null)==='sealed'){throw new \RuntimeException('Synthetic local guard refusal');}
        }]);
    }
};
foreach(['success','unsent','timeout','cap','off'] as $mode){
    $prepare($mode);$op=$nsNew(placeholderComFixtureName('writer-'.$mode.'-placeholder.com'));$GLOBALS['purchaseOperation']=$op->operation_key;
    $before=$ledger->usage($binding);$money=$nativeMoney();
    if($mode==='cap'){config([$accountKey.'.daily_spend_limit_usd'=>'0.00']);}
    if($mode==='off'){config(['domains.namesilo_live.registration'=>false]);}
    $result=$runRegistration($op);
    $after=$ledger->usage($binding);$writes=count(array_filter($GLOBALS['physical'],static fn($c)=>$c==='registerDomain'));
    same($money,$nativeMoney(),'Execution preserves native invoices, payments, credits and jobs');
    if($mode==='success'){
        same('pending',$result->state,'Accepted purchase retained: '.$result->state.':'.($result->safe_code??'none').':'.implode(',',$GLOBALS['physical']).':'.implode(',',$registration->calls));same(1,$writes,'One physical purchase');
        same(['auto_renew'],array_keys($result->attempt['corrections']),'Auto-renew needs separately authorized work');
        expect(!in_array('removeAutoRenewal',$GLOBALS['physical'],true),'Purchase grant never performs cleanup mutation');
    }elseif($mode==='unsent'){
        same(['held','acquisition_definitely_unsent'],[$result->state,$result->safe_code],'Local sealed refusal retains definitely-unsent result');
        same('sealed',$result->attempt['seal'],'Seal never reset for retry');same(0,$writes,'Local refusal has zero physical purchase');
        RefundProof::requireUnsent($result,$result->domain);
        commerceReject(fn()=>$result->update(['attempt'=>array_replace($result->attempt,['execution'=>null]) ]));$result->refresh();
    }elseif($mode==='timeout'){
        same('uncertain',$result->state,'Lost response stays unknown');same(1,$writes,'No replay after lost response');
        commerceReject(fn()=>RefundProof::requireUnsent($result,$result->domain));
    }else{
        same('held',$result->state,'Unadmitted/capped purchase held');same(0,$writes,'No mutation when admission refuses');
        expect(($result->attempt['seal']??'absent')==='absent','Refusal precedes seal');
    }
    same($before['spend_micros']+(in_array($mode,['cap','off'],true)?0:6000000),$after['spend_micros'],'Exact fresh funded price charged to our reservation once');
    $physical=$GLOBALS['physical'];$spent=$after['spend_micros'];
    $engine->execute($result->id);
    same($physical,$GLOBALS['physical'],'Retained operation cannot repeat a physical send');same($spent,$ledger->usage($binding)['spend_micros'],'Retry cannot reserve the purchase twice');
    config(['domains.namesilo_live.registration'=>true,'domains.provider_send_guard'=>null]);
}
echo "Dormant NameSilo acquisition reserves before seal; local unsent and lost responses retain distinct durable outcomes.\n";
$nativeSuccessful=true;
