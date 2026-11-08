<?php
// Enom 444f31c TransferOrders precheck scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Environment, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{CheckoutEligibility, Rdap, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture(true)); $calls=0; $status=200;$created='2020-01-01T00:00:00Z';$statuses=['active'];
$rdap=new Rdap(static function($url)use(&$calls,&$status,&$created,&$statuses){$calls++;same(0,Illuminate\Support\Facades\DB::transactionLevel(),'RDAP outside locks');
    return ['status'=>str_contains($url,'dns.json')?200:$status,'body'=>json_encode(str_contains($url,'dns.json')
        ?['services'=>[[['com'],['https://rdap.example.com/']]]]
        :['objectClassName'=>'domain','ldhName'=>'example.com','status'=>$statuses,'events'=>[
            ['eventAction'=>'registration','eventDate'=>$created],['eventAction'=>'expiration','eventDate'=>'2027-01-01T00:00:00Z']]])];});
$eligibility=activeFixtureEligibility($bindings,$clock,new TransferPrecheck($rdap));
$s=$sellers->selection('com','transfer',1,$now);$server=App\Models\Server::find($s->serverId);
commerceReject(fn()=>$eligibility->observe('example.com',$s,[1]));same(0,$calls,'No lookup without explicit allowlist');
$server->settings()->create(['key'=>'domains_transfer_endings','value'=>'com']);
// Worker preflight builds an internal selection without a public cart receipt.
// Retired registry columns cannot supply or veto its current typed provider facts.
Illuminate\Support\Facades\DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['registry_fields'=>null]);
$workerSelection=new \Paymenter\Extensions\Servers\Domains\Data\SaleSelection('com',$s->serverId,$s->registrar,$s->fingerprint,0,'transfer',1,'USD',0,0);
same([60,60],TransferPrecheck::rules($workerSelection),'Worker preflight uses current typed registry facts without a cart receipt');
same([60,60],Illuminate\Support\Facades\DB::transaction(fn()=>TransferPrecheck::rules($workerSelection,true)),'Seal rules use the same locked current facts');
$foreignSelection=new \Paymenter\Extensions\Servers\Domains\Data\SaleSelection('com',$s->serverId,$s->registrar,str_repeat('f',64),0,'transfer',1,'USD',0,0);
commerceReject(fn()=>TransferPrecheck::rules($foreignSelection));
$beforeFactsClock=$clock->instant;$clock->instant=$clock->instant->modify('+2 days');commerceReject(fn()=>TransferPrecheck::rules($workerSelection));$clock->instant=$beforeFactsClock;

$token=$eligibility->observe('example.com',$s,[1]);same($s->serverId,$eligibility->require($token,'example.com',[1])->serverId,'Accepted transfer receipt');
same(2,$calls,'One bootstrap plus one domain read');
$created='2026-10-01T00:00:00Z';$statuses=['client transfer prohibited'];
try{$eligibility->observe('example.com',$s,[1]);throw new RuntimeException('Recent registry registration must refuse');}
catch(\Paymenter\Extensions\Servers\Domains\Services\TransferRestriction $e){same(['transfer_creation_lock','2026-11-30'],[$e->getMessage(),$e->retryAt?->format('Y-m-d')],'The dated registry window explains the lock');}
$created='2020-01-01T00:00:00Z';
try{$eligibility->observe('example.com',$s,[1]);throw new RuntimeException('An undated registrar lock must refuse');}
catch(\Paymenter\Extensions\Servers\Domains\Services\TransferRestriction $e){same(['transfer_locked',null],[$e->getMessage(),$e->retryAt],'An undated lock invents no retry date');}
$statuses=['active'];$reads->available=false;commerceReject(fn()=>$eligibility->observe('example.com',$s,[1]));
echo "Native transfer eligibility retains dated restrictions and registrar authority\n";$nativeSuccessful=true;
