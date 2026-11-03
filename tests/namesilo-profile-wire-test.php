<?php
// Full installed acquisition-to-physical-wire proof: two independent mutation grants, all responses synthetic.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations,Installation,LiveReservations,ProfileContactOperations,RegistrationPreflight,RegistrationProfiles,WriteAcceptance,WriteFeatures};
expect(is_file(dirname(__DIR__).'/Domains/Services/ProfileContactOperations.php'),'Installed profile creation needs dedicated durable send authority');
(new Installation)->install();$installedFixture=true;$fixtureEnvironment=Environment::Live;
config(['app.url'=>'https://billing.example.com']);
require __DIR__.'/Support/namesilo-registration-fixture.php';
app()->instance(Clock::class,$clock);
function curl_init(): object {return new \stdClass;}
function curl_setopt_array($h,array $options):bool {$GLOBALS['profileOptions']=array_replace($GLOBALS['profileOptions']??[],$options);return true;}
function curl_exec($h):bool {
    $url=$GLOBALS['profileOptions'][CURLOPT_URL];parse_str(parse_url($url,PHP_URL_QUERY),$p);
    $command=basename(parse_url($url,PHP_URL_PATH));unset($p['key'],$p['version'],$p['type']);
    foreach(['auto_renew','private','years'] as $key){if(isset($p[$key])){$p[$key]=(int)$p[$key];}}
    same('https://www.namesilo.com/apibatch/'.$command,strtok($url,'?'),'All physical work uses fixed batch origin');
    $GLOBALS['profilePhysical'][]=$command;$body=($GLOBALS['profileReply'])($command,$p);
    return ($GLOBALS['profileOptions'][CURLOPT_WRITEFUNCTION])($h,$body)===strlen($body);
}
function curl_getinfo($h,int $option):int {return 200;}
function curl_close($h):void {}
$account=Account::where('server_id',$binding->serverId)->sole();
config(['domains.namesilo_live.site'=>'https://billing.example.com','domains.namesilo_live.accounts'=>[$account->stable_identity=>[
    'fingerprint'=>$binding->fingerprint,'daily_call_cap'=>1000,'daily_spend_limit_usd'=>'1000.00','currency'=>'USD','balance_funding'=>true]],
    'domains.namesilo_live.catalogue'=>true,'domains.namesilo_live.registration'=>true,'domains.namesilo_live.management'=>true]);
$ledger=new LiveReservations(new NameSiloLiveReservationPolicy);
foreach(['ok','unsent','lost','badread','readfail','drift'] as $mode){
    $op=$nsNew(placeholderComFixtureName('durable-wire-'.$mode.'-placeholder.com'));$GLOBALS['profilePhysical']=[];
    $registration->calls=[];$registration->mode=$mode==='badread'?'wrongcontact':'300';
    $purchaseTransport=new AdmittedTransport($binding,static fn(bool $write)=>['api_key'=>'placeholder-api-key']);
    $profileTransport=new AdmittedTransport($binding,static fn(bool $write)=>['api_key'=>'placeholder-api-key']);
    $GLOBALS['profileReply']=static function($command,$p)use($op,$mode,$exchange,$profileTransport,$purchaseTransport):string {
        same(0,DB::transactionLevel(),'Physical send outside financial and ledger locks');
        if($command==='contactAdd'){
            $row=ProfileContactOperation::where('operation_id',$op->id)->sole();same('sealed',$row->state,'Independent profile seal committed');
            expect(isset($row->attempt['dispatch_at']),'Independent profile authority consumed');same('absent',$op->fresh()->attempt['seal'],'Contact creation precedes purchase seal');
            expect(!$profileTransport->reservation->unconsumed((int)$row->id),'Profile physical handoff consumes its own grant');
            expect(!$purchaseTransport->reservation->unconsumed((int)$op->id),'Profile never arms the purchase grant');
            if($mode==='lost'){throw new \RuntimeException('Synthetic lost contactAdd response');}
        }
        if($command==='contactList'&&isset($p['contact_id'])){
            if($mode==='readfail'){throw new \RuntimeException('Synthetic contact readback loss');}
            if($mode==='drift'){$current=$op->fresh();$current->update(['policy'=>[...$current->policy,'synthetic_drift'=>true]]);}
        }
        if($command==='registerDomain'){
            same('verified',ProfileContactOperation::where('operation_id',$op->id)->sole()->state,'Purchase requires durable verified profile');
            same('sealed',$op->fresh()->attempt['seal'],'Purchase owns its separate funded seal');
        }
        return $exchange('https://ote.namesilo.com/apibatch',$command,$p,25000);
    };
    config(['domains.provider_send_guard'=>$mode==='unsent'?static function($provider,$env,$command):void {
        if($command==='contactAdd'){throw new \RuntimeException('Synthetic profile prewire refusal');}
    }:null]);
    $features=new WriteFeatures(registration:true);
    $profileOps=new ProfileContactOperations($binding,$claims,$clock,$profileTransport->reservation,$profileTransport->exchange());
    $profiles=new RegistrationProfiles($binding,$claims,$clock,static fn()=>throw new \LogicException('No legacy profile sender'),operations:$profileOps);
    $accepted=new WriteAcceptance($binding,true,true,25,$profiles);$fresh=new RegistrationPreflight($freshBindings,$features,$accepted,$profiles);
    $protocol=new Registration($binding,$profiles,$purchaseTransport->exchange());$protocol->useReservation($purchaseTransport->reservation);
    $bridge=new Writer($binding,$features,$accepted,static function()use(&$engine){return $engine;},$protocol);$engine=new DomainOperations($claims,$fresh,$bridge,$clock);
    \Illuminate\Support\Facades\Cache::flush();$before=$ledger->usage($binding);$money=$nativeMoney();$result=$runRegistration($op);
    $row=ProfileContactOperation::where('operation_id',$op->id)->sole();
    same(match($mode){'ok'=>'verified','unsent'=>'unsent','lost'=>'uncertain',default=>'created'},$row->state,'Durable physical profile outcome');
    same($mode==='ok'?'pending':'held',$result->state,'Only proven profile reaches purchase');
    same($mode==='unsent'?0:1,count(array_filter($GLOBALS['profilePhysical'],static fn($c)=>$c==='contactAdd')),'At most one physical contact creation');
    same($mode==='ok'?1:0,count(array_filter($GLOBALS['profilePhysical'],static fn($c)=>$c==='registerDomain')),'Only successful proof consumes purchase grant');
    same($before['spend_micros']+($mode==='ok'?6000000:0),$ledger->usage($binding)['spend_micros'],'Profile creation reserves a call but no spend');
    same(1,Operation::where('domain_id',$op->domain_id)->count(),'Profile work never becomes a domain operation');same($money,$nativeMoney(),'Native finance and jobs unchanged');
    $physical=$GLOBALS['profilePhysical'];$engine->execute($result->id);same($physical,$GLOBALS['profilePhysical'],'Retained outcome cannot replay');
}
config(['domains.provider_send_guard'=>null]);
echo "Installed NameSilo profiles use independent durable authority and physical one-send grants before acquisition.\n";
$nativeSuccessful=true;
