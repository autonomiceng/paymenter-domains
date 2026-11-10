<?php
// SQL reservations and encrypted account configuration require the native fixture. Wire calls are synthetic only.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/native-app.php';
require_once __DIR__.'/Support/FakeRegistrar.php';
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{Installation, LiveReservationLimit, LiveReservations};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
expect(is_file(dirname(__DIR__).'/Domains/Registrars/NameSilo/AdmittedTransport.php'),'NameSilo must provide a budgeted, one-send admitted transport');
// Namespaced cURL functions make accidental sockets impossible while exercising the physical boundary.
function hrtime(bool $number=false): int {return $GLOBALS['wire']['ns'];}
function curl_init(): object {++$GLOBALS['wire']['inits'];return new \stdClass;}
function curl_setopt_array($handle,array $options): bool {
    ++$GLOBALS['wire']['setopts'];
    if($GLOBALS['wire']['setopts']===2&&$GLOBALS['wire']['secondSetopt']!==null){($GLOBALS['wire']['secondSetopt'])();}
    $GLOBALS['wire']['options']=array_replace($GLOBALS['wire']['options'],$options);return true;
}
function curl_exec($handle): bool {
    ++$GLOBALS['wire']['sends'];
    parse_str(parse_url($GLOBALS['wire']['options'][CURLOPT_URL],PHP_URL_QUERY),$p);
    $GLOBALS['wire']['parameters'][]=$p;
    if($GLOBALS['wire']['timeout']){throw new \RuntimeException($GLOBALS['wire']['options'][CURLOPT_URL]);}
    foreach($GLOBALS['wire']['chunks'] as $chunk){if(($GLOBALS['wire']['options'][CURLOPT_WRITEFUNCTION])($handle,$chunk)!==strlen($chunk)){return false;}}
    return true;
}
function curl_getinfo($handle,int $option): int {return $GLOBALS['wire']['status'];}
function curl_close($handle): void {++$GLOBALS['wire']['closed'];}
function resetWire(array $overrides=[]): void {$GLOBALS['wire']=$overrides+['ns'=>0,'setopts'=>0,'secondSetopt'=>null,'options'=>[],'sends'=>0,'inits'=>0,'closed'=>0,'parameters'=>[],'timeout'=>false,'status'=>200,'chunks'=>['{}']];}
ini_set('zend.exception_ignore_args','0');
resetWire();(new Installation)->install();
$clock=new FakeClock(new \DateTimeImmutable('2026-11-02T12:00:00Z'));app()->instance(Clock::class,$clock);
$server=Server::create(['name'=>'Synthetic NameSilo','type'=>'server','extension'=>'Domains','enabled'=>true]);
$account=Account::withVerifiedBindingWrite(fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>'namesilo','environment'=>'live',
    'stable_identity'=>'local-account-1']));
Account::withVerifiedBindingWrite(fn()=>$account->update(['live_site'=>'https://billing.example.com','processing_enabled'=>true]));
$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account);
$settings=['fingerprint'=>$binding->fingerprint,'daily_call_cap'=>100,'daily_spend_limit_usd'=>'20.00','currency'=>'USD','balance_funding'=>true];
config(['app.url'=>'https://billing.example.com','domains.namesilo_live.site'=>'https://billing.example.com',
    'domains.namesilo_live.accounts'=>['local-account-1'=>$settings]]);
foreach(['catalogue','registration','transfer','renewal','management'] as $feature){config(['domains.namesilo_live.'.$feature=>true]);}
$key='placeholder-api-key';$writeChecks=[];
$transport=new AdmittedTransport($binding,static function(bool $write)use(&$key,&$writeChecks):array {$writeChecks[]=$write;return ['api_key'=>$key];});
$exchange=$transport->exchange();$origin='https://www.namesilo.com/apibatch';
$read=fn()=>$transport->sendWithin(Api\Environment::Live,Api\RequestContext::Background,Api\RegistrarRequest::balance(),$key,20000);
$ledger=new LiveReservations(new NameSiloLiveReservationPolicy);
$used=fn()=>$ledger->usage($binding);
$deny=static function(callable $work):void {
    $before=$GLOBALS['wire']['sends'];$refused=false;
    try{$work();}catch(\Throwable $e){$refused=true;expect(!str_contains($e->getMessage().$e->getTraceAsString(),'placeholder-api-key'),'Errors redact credentials');same(null,$e->getPrevious(),'No raw exception cause');}
    expect($refused,'Unsafe send refused');same($before,$GLOBALS['wire']['sends'],'Refusal performs no physical send');
};
// Reads consume one durable call immediately before the physical send; caller keys cannot override saved credentials.
same('{}',$read()->body(),'Admitted read returns bounded evidence');same(['used'=>1,'spend_micros'=>0],$used(),'One physical read consumes one call');
same($origin.'/getAccountBalance',strtok($GLOBALS['wire']['options'][CURLOPT_URL],'?'),'Fixed background route');
$transport->send(Api\Environment::Live,Api\RequestContext::Interactive,Api\RegistrarRequest::balance(),$key);
same('https://www.namesilo.com/api/getAccountBalance',strtok($GLOBALS['wire']['options'][CURLOPT_URL],'?'),'Interactive reads use api');
$deny(fn()=>$transport->send(Api\Environment::Sandbox,Api\RequestContext::Background,Api\RegistrarRequest::balance(),$key));
$deny(fn()=>$transport->send(Api\Environment::Live,Api\RequestContext::Background,Api\RegistrarRequest::balance(),'wrong-placeholder'));
$deny(fn()=>$exchange('https://example.com/apibatch','getAccountBalance',[],1000));
$deny(fn()=>$exchange($origin,'addAutoRenewal',['domain'=>'example.com'],1000));
$deny(fn()=>$exchange($origin,'getAccountBalance',['key'=>'override'],1000));
$params=['domain'=>'example.com','years'=>1,'auto_renew'=>0];
$renew=fn(array $p)=>$exchange($origin,'renewDomain',$p,20000);
$deny(fn()=>$renew($params));
$grant=$transport->reservation;
// The same grant cannot change command, payload, route or send twice; reads do not spend its reserved purchase.
$grant->run('renewal',41,function()use($grant,$params,$deny,$renew,$read,$used,$exchange,$origin):void {
    $grant->reserve('renewDomain',$params,new Money('10.00','USD'));same(['used'=>3,'spend_micros'=>10000000],$used(),'Pre-seal reservation counts call and exact USD cost once');
    expect($grant->unconsumed(41),'Operation owns unsent reservation');expect(!$grant->unconsumed(42),'Other operation has no unsent proof');
    $deny(fn()=>$grant->reserve('renewDomain',$params,new Money('10.00','USD')));
    $deny(fn()=>$renew([...$params,'domain'=>'other.example.com']));
    $deny(fn()=>$exchange('https://www.namesilo.com/api','renewDomain',$params,20000));
    $read();$renew($params);same(['version'=>'1','type'=>'json','key'=>'placeholder-api-key','domain'=>'example.com','years'=>'1','auto_renew'=>'0'],array_slice($GLOBALS['wire']['parameters'],-1)[0],'Renewal sends only balance-funded annual off mapping');expect(!$grant->unconsumed(41),'Physical handoff consumes grant');$deny(fn()=>$renew($params));
});
same(['used'=>4,'spend_micros'=>10000000],$used(),'Purchase consumes reserved call once, with independently counted read');
$deny(fn()=>$renew($params));
foreach([[...$params,'payment_id'=>'1'],[...$params,'auto_renew'=>1],[...$params,'years'=>2],[...$params,'private'=>0]] as $unsafe){
    $deny(fn()=>$grant->run('renewal',42,fn()=>$grant->reserve('renewDomain',$unsafe,new Money('1.00','USD'))));
}
$deny(fn()=>$grant->run('renewal',42,fn()=>$grant->reserve('renewDomain',$params,new Money('1.00','EUR'))));
$deny(fn()=>$grant->run('renewal',42,fn()=>$grant->reserve('renewDomain',$params)));
// Fresh deployment/account/feature and credential checks apply to cached ports.
foreach(['catalogue','renewal'] as $feature){config(['domains.namesilo_live.'.$feature=>false]);$deny(fn()=>$grant->run('renewal',42,fn()=>$renew($params)));config(['domains.namesilo_live.'.$feature=>true]);}
Account::withVerifiedBindingWrite(fn()=>$account->update(['processing_enabled'=>false]));$deny(fn()=>$grant->run('renewal',42,fn()=>$renew($params)));$read();
Account::withVerifiedBindingWrite(fn()=>$account->update(['processing_enabled'=>true]));
foreach([['currency'=>'EUR'],['balance_funding'=>false],['fingerprint'=>'wrong']] as $bad){
    config(['domains.namesilo_live.accounts.local-account-1'=>[...$settings,...$bad]]);$deny($read);
}
config(['domains.namesilo_live.accounts.local-account-1'=>$settings]);
config(['app.url'=>'https://copied.example.com']);$deny($read);config(['app.url'=>'https://billing.example.com']);
$key='bad key';$deny($read);$key='placeholder-api-key';
// Limits, guards, deadlines and UTC rollover fail closed without releasing prior reservations.
$before=$used();config(['domains.namesilo_live.accounts.local-account-1.daily_call_cap'=>$before['used']]);$deny($read);
same($before,$used(),'Cap refusal creates no new capacity');config(['domains.namesilo_live.accounts.local-account-1'=>$settings]);
config(['domains.namesilo_live.accounts.local-account-1.daily_spend_limit_usd'=>'10.00']);
$deny(fn()=>$grant->run('renewal',43,fn()=>$grant->reserve('renewDomain',$params,new Money('0.01','USD'))));
config(['domains.namesilo_live.accounts.local-account-1'=>$settings]);
$grant->run('renewal',44,function()use($grant,$params,$clock,$deny,$renew):void {
    $grant->reserve('renewDomain',$params,new Money('1.00','USD'));$clock->instant=$clock->instant->modify('+1 day');$deny(fn()=>$renew($params));
});
$clock->instant=$clock->instant->modify('-1 day');
$before=$used();config(['domains.provider_send_guard'=>static function():void {throw new \RuntimeException('placeholder-api-key');}]);$deny($read);
config(['domains.provider_send_guard'=>null]);same($before['used']+1,$used()['used'],'Unsent guard reservation remains counted');
config(['domains.provider_send_guard'=>static function():void {$GLOBALS['wire']['ns']=30000000000;}]);$deny($read);config(['domains.provider_send_guard'=>null]);resetWire();
// A lost reply stays consumed; redacted evidence cannot turn it into another purchase.
$grant->run('renewal',45,function()use($grant,$params,$renew,$deny):void {
    $grant->reserve('renewDomain',$params,new Money('1.00','USD'));$GLOBALS['wire']['timeout']=true;
    try{$renew($params);throw new \LogicException('Expected timeout');}catch(\RuntimeException $e){
        expect(!str_contains($e->getMessage().$e->getTraceAsString(),'placeholder-api-key'),'Timeout URL discarded');same(null,$e->getPrevious(),'Timeout cause discarded');
    }
    same(1,$GLOBALS['wire']['sends'],'Exactly one attempt on timeout');expect(!$grant->unconsumed(45),'Lost reply grants no unsent proof');$deny(fn()=>$renew($params));
});
foreach([302,500] as $status){
    resetWire(['status'=>$status]);$denyResponse=false;try{$read();}catch(\RuntimeException){$denyResponse=true;}
    expect($denyResponse,'Redirect or server error is unresolved');same(1,$GLOBALS['wire']['sends'],'HTTP error not retried');
}
resetWire(['chunks'=>[str_repeat('x',Api\CurlTransport::BODY_LIMIT),'x']]);$denyResponse=false;try{$read();}catch(\RuntimeException){$denyResponse=true;}expect($denyResponse,'Oversize body is unresolved');same(1,$GLOBALS['wire']['sends'],'Oversize body not retried');
foreach([CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS] as $option=>$value){same($value,$GLOBALS['wire']['options'][$option],'Fixed TLS and routing options');}
expect(in_array(true,$writeChecks,true)&&in_array(false,$writeChecks,true),'Credentials freshly checked for reads and writes');
// Existing purchase mappings retain explicit privacy and auto-renew-off flags at the admitted boundary.
resetWire();
foreach([
    ['registration',Api\PurchaseMapping::register('example.com',1,false,'123',['ns1.example.net','ns2.example.net'])],
    ['transfer',Api\PurchaseMapping::transfer('example.com','placeholder-epp',true,'123',['ns1.example.net','ns2.example.net'])],
] as [$feature,$mapping]){
    $grant->run($feature,46,function()use($grant,$mapping,$exchange,$origin):void {
        $grant->reserve($mapping->command,$mapping->parameters(),new Money('1.00','USD'));
        $exchange($origin,$mapping->command,$mapping->parameters(),20000);
        $wire=array_slice($GLOBALS['wire']['parameters'],-1)[0];same('0',$wire['auto_renew'],'Purchase explicitly disables provider auto-renew');
        same((string)$mapping->parameters()['private'],$wire['private'],'Explicit privacy choice survives transport');expect(!isset($wire['payment_id']),'No external funding selection');
    });
}
$grant->run('management',47,function()use($grant,$exchange,$origin):void {
    $grant->reserve('domainLock',['domain'=>'example.com']);$exchange($origin,'domainLock',['domain'=>'example.com'],20000);
});
same(3,$GLOBALS['wire']['sends'],'Registration, transfer and free management each send exactly once');
// A failed purchase send keeps only its fixed failure kind, so staff can see the HTTP status without the body or URL.
resetWire(['status'=>500]);
$grant->run('registration',51,function()use($grant,$exchange,$origin):void {
    $mapping=Api\PurchaseMapping::register('example.com',1,false,'123',['ns1.example.net','ns2.example.net']);
    $grant->reserve($mapping->command,$mapping->parameters(),new Money('1.00','USD'));
    $failure=null;try{$exchange($origin,$mapping->command,$mapping->parameters(),20000);}catch(\Paymenter\Extensions\Servers\Domains\Data\SendFailure $error){$failure=$error;}
    same(['category'=>'http 500','timeout_ms'=>20000],$failure?->facts(),'Purchase HTTP error keeps only its status for staff');
    same(null,$failure->getPrevious(),'No raw cause');expect(!str_contains($failure->getMessage().$failure->getTraceAsString(),'placeholder-api-key'),'Failure redacts credentials');
    same(1,$GLOBALS['wire']['sends'],'Failed purchase is sent once');expect(!$grant->unconsumed(51),'Failed purchase consumes its grant');
});
resetWire();
// Existing management discovery lists glue through the read path and pays for exactly one call.
resetWire();$before=$used();$listed=false;
try{$listed=$exchange($origin,'listRegisteredNameServers',['domain'=>'example.com'],20000)==='{}';}catch(LiveReservationLimit){}
expect($listed,'Existing glue inventory read reaches the admitted transport');
same(['used'=>$before['used']+1,'spend_micros'=>$before['spend_micros']],$used(),'Glue list reserves one call and no spend');
same(1,$GLOBALS['wire']['sends'],'Glue list sends once without a mutation grant');
same($origin.'/listRegisteredNameServers',strtok($GLOBALS['wire']['options'][CURLOPT_URL],'?'),'Glue list keeps the fixed batch route');
// A glue mutation needs its own management reservation and cannot replay that grant.
resetWire();$before=$used();$glue=['domain'=>'example.com','new_host'=>'ns1','ip1'=>'192.0.2.1'];
$sendGlue=fn()=>$exchange($origin,'addRegisteredNameServer',$glue,20000);
$deny($sendGlue);
$grant->run('management',48,function()use($grant,$glue,$sendGlue,$deny):void {
    $grant->reserve('addRegisteredNameServer',$glue);$sendGlue();
    expect(!$grant->unconsumed(48),'Glue mutation consumes its single-send grant');$deny($sendGlue);
});
same(['used'=>$before['used']+1,'spend_micros'=>$before['spend_micros']],$used(),'Glue mutation counts its reservation once without spend');
same(1,$GLOBALS['wire']['sends'],'Glue mutation sends once and refuses replay');
// C1: DS list is a read; add/delete need the exact five keys and a canonical tuple before any reservation or send.
same(['catalogue','management','management'],array_map([AdmittedTransport::class,'feature'],['dnsSecListRecords','dnsSecAddRecord','dnsSecDeleteRecord']),'DS list reads and DS writes manage');
$ds=['domain'=>'example.com','digest'=>str_repeat('AB',32),'keyTag'=>123,'digestType'=>2,'alg'=>13];
resetWire();$before=$used();$id=60;
foreach(['extra key'=>$ds+['ttl'=>3600],'case alias'=>array_diff_key($ds,['keyTag'=>1])+['keytag'=>123],'credential key'=>$ds+['key'=>'override'],
    'lowercase digest'=>['digest'=>strtolower($ds['digest'])]+$ds,'short digest'=>['digest'=>'AB']+$ds,'string key tag'=>['keyTag'=>'123']+$ds,
    'non-canonical domain'=>['domain'=>'Example.com']+$ds] as $label=>$p) {
    $grant->run('management',$id++,function()use($grant,$p,$label):void {
        $refused=false;try{$grant->reserve('dnsSecAddRecord',$p);}catch(LiveReservationLimit|\DomainException){$refused=true;}
        expect($refused,'DS '.$label.' refused before the grant');
    });
}
same([0,$before],[$GLOBALS['wire']['sends'],$used()],'Refused DS shapes reserve and send nothing');
$sendDs=fn(string $command)=>$exchange($origin,$command,$ds,20000);
$deny(fn()=>$sendDs('dnsSecAddRecord'));
$grant->run('management',$id++,function()use($grant,$ds,$sendDs,$deny):void {
    $grant->reserve('dnsSecAddRecord',$ds);$deny(fn()=>$sendDs('dnsSecDeleteRecord'));$sendDs('dnsSecAddRecord');$deny(fn()=>$sendDs('dnsSecAddRecord'));
});
same([1,$before['used']+1],[$GLOBALS['wire']['sends'],$used()['used']],'Exact DS add sends once on its own grant and never replays');
same(['version','type','key','domain','digest','keyTag','digestType','alg'],array_keys(array_slice($GLOBALS['wire']['parameters'],-1)[0]),'DS add keeps its camel-case wire keys');
resetWire();$deny(fn()=>$exchange($origin,'dnsSecListRecords',['domain'=>'example.com','page'=>'2'],20000));
$exchange($origin,'dnsSecListRecords',['domain'=>'example.com'],20000);same(1,$GLOBALS['wire']['sends'],'DS list reads once without a mutation grant');
// UTC reservation days also bound physical timeouts, including fractional milliseconds and shorter caller deadlines.
$clock->instant=new \DateTimeImmutable('2026-11-02T15:59:59.123456-08:00');resetWire();
$read();same(876,$GLOBALS['wire']['options'][CURLOPT_TIMEOUT_MS],'Read timeout stops before the reserved UTC day ends');
same(876,$GLOBALS['wire']['options'][CURLOPT_CONNECTTIMEOUT_MS],'Connect timeout also fits the remaining UTC day');
$transport->sendWithin(Api\Environment::Live,Api\RequestContext::Background,Api\RegistrarRequest::balance(),$key,100);
same(100,$GLOBALS['wire']['options'][CURLOPT_TIMEOUT_MS],'Shorter caller timeout is preserved');
$grant->run('management',49,function()use($grant,$exchange,$origin):void {
    $grant->reserve('domainLock',['domain'=>'example.com']);$exchange($origin,'domainLock',['domain'=>'example.com'],20000);
    same(876,$GLOBALS['wire']['options'][CURLOPT_TIMEOUT_MS],'Mutation timeout stays within its reserved UTC day');
    expect(!$grant->unconsumed(49),'Day-bounded physical mutation consumes the original grant');
});
$clock->instant=new \DateTimeImmutable('2026-11-02T23:59:59.999500Z');$before=$used();$deny($read);
same($before['used']+1,$used()['used'],'Submillisecond refusal retains its original counted call');
$grant->run('management',50,function()use($grant,$exchange,$origin,$deny):void {
    $grant->reserve('domainLock',['domain'=>'example.com']);$deny(fn()=>$exchange($origin,'domainLock',['domain'=>'example.com'],20000));
    expect($grant->unconsumed(50),'No remaining whole millisecond refuses before consuming a mutation grant');
});
// Cross midnight after timeout calculation but before the final reserved-day check and physical handoff.
$clock->instant=new \DateTimeImmutable('2026-11-02T23:59:59.500000Z');$reservedDay=LiveReservations::day();$before=$used();
resetWire(['secondSetopt'=>static function()use($clock):void {$clock->instant=new \DateTimeImmutable('2026-11-03T00:00:00Z');}]);
$rolloverRefused=false;try{$read();}catch(LiveReservationLimit){$rolloverRefused=true;}
expect($rolloverRefused,'Read must refuse a reserved-day rollover during final cURL setup');
same(2,$GLOBALS['wire']['setopts'],'Rollover occurs in the second cURL option update');
same(0,$GLOBALS['wire']['sends'],'Read crossing its reserved day performs zero physical sends');
$clock->instant=new \DateTimeImmutable($reservedDay.'T23:59:59.500000Z');
same($before['used']+1,$used()['used'],'Rollover refusal retains the original day reservation');
echo "NameSilo admitted transport enforces reservations, fixed routes, one send and redacted uncertain outcomes.\n";
$nativeSuccessful=true;
