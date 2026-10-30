<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-run-budget.php';
require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/WireLimit.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{CurlTransport,ReadCommand};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomTestRunBudget as Budget,EnomTestRunBudgetSchema as Tables,EnomTestCalls as Calls,EnomTestWriteReservation as Writes,Installation,RegistrarRegistry,Rdap,RenewalAcceptance,Runtime,TransferPrecheck,WriteAcceptance,WriteFeatures};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
app()->instance(Installation::class,new Installation);app(Installation::class)->install();
app(Installation::class)->install(); // Retained schema reinstall does not lose identity.
$policy=runPolicy('transport',24,24);config(['domains.enom_test_run_policy'=>$policy]);
Budget::prepare($policy,24);Budget::activate($policy);
$transport=new CurlTransport;$seen=[];
config(['domains.provider_send_guard'=>static function($registrar,$environment,$command)use(&$seen):void{
    if($environment!=='test'){return;}
    same('enom',$registrar,'Only Enom uses Enom credits');
    $permit=Budget::pendingPermit();expect(is_string($permit),'Native reserves before host guard');Budget::assertPermit($permit);
    $seen[]=$permit;runAdvance('+2 seconds'); // Midnight falls between native preparation and physical consumption.
}]);
resetWire(['during'=>static function()use(&$seen):void{
    same(1,Budget::status()['used'],'Credit committed before curl executes');
    runRefuses(fn()=>Budget::assertPermit(end($seen)),'Physical send sees an already consumed permit');
}]);
runRefuses(fn()=>$transport->send(Environment::Test,ReadCommand::Info,['SLD'=>'example','TLD'=>'com'],'synthetic','token',1000),'Bootstrap permits only account verification');
runRefuses(fn()=>Budget::bindAccount($policy,str_repeat('a',64)),'Bootstrap cannot bind before verification attempt');
same('xml',$transport->send(Environment::Test,ReadCommand::Balance,[],'synthetic','token',1000),'Bootstrap balance crosses midnight');
same(1,count($GLOBALS['enomWire']['forms']),'Exactly one bootstrap physical call');
runRefuses(fn()=>$transport->send(Environment::Test,ReadCommand::Balance,[],'synthetic','token',1000),'Started bootstrap cannot repeat');
$server=Server::create(['type'=>'server','name'=>'Synthetic run account','extension'=>'Domains','hostname'=>'example.invalid','enabled'=>true]);
$b=new AccountBinding('enom',(int)$server->id,Environment::Test,'synthetic');
Account::withVerifiedBindingWrite(static fn()=>Account::create(['server_id'=>$server->id,'registrar_key'=>'enom','environment'=>'test','stable_identity'=>'synthetic','processing_enabled'=>false]));
Budget::bindAccount($policy,$b->fingerprint);
$display=\Paymenter\Extensions\Servers\Domains\Admin\Support\RegistrarFacts::calls(Account::where('server_id',$server->id)->sole());
same('API calls this run',$display['label'],'Run display does not promise a midnight reset');
expect(!str_contains($display['help'],'per day'),'Run help describes deployment policy');
resetWire();same('xml',$transport->send(Environment::Test,ReadCommand::Balance,[],'SYNTHETIC','token',1000),'Direct reads resolve canonical native account identity');
runRefuses(fn()=>$transport->send(Environment::Test,ReadCommand::Balance,[],'other','token',1000),'Existing mismatched account is not fresh bootstrap');
$bindings=new AccountBindings(new RegistrarRegistry);app()->instance(Runtime::class,new Runtime);app()->instance(ReadLimiter::class,new WireLimit(100));
$factory=RuntimeFactory::make($b,$bindings,$runClock,new WriteFeatures,new WriteAcceptance($b),new RenewalAcceptance($b),new TransferPrecheck(new Rdap(static fn()=>null)),static fn()=>['uid'=>'synthetic','token'=>'token']);
$wire=(new ReflectionFunction($factory))->getStaticVariables()['wire'];
$exchange=static fn(string $role)=>(new ReflectionMethod($wire,'exchange'))->invoke($wire,$role);
$origin='https://resellertest.enom.com/interface.asp';
resetWire();same('xml',$exchange('management')($origin,'GetBalance',[],1000),'Runtime read uses a single permit');
Calls::withPurchase($b,function()use($b,$exchange,$origin,&$seen):void{
    Calls::reservePurchase($b);$original=Calls::prepareSend($b,true);runAdvance('+1 day');resetWire();
    same('xml',$exchange('registration')($origin,'Purchase',[],1000),'Purchase sends across midnight');
    same($original,end($seen),'Host receives original pre-seal purchase permit');
    runRefuses(fn()=>$exchange('registration')($origin,'Purchase',[],1000),'Purchase cannot send twice');
},101);
foreach(['renewal'=>['Extend','renewal'],'management'=>['SetRegLock','management']] as $role=>[$command,$action]){
    resetWire();runRefuses(fn()=>$exchange($role)($origin,$command,[],1000),'Mutation requires original pre-seal scope');
    Writes::run($b,$action,102,function()use($b,$exchange,$origin,$role,$command,$action,&$seen):void{
        Writes::reserve($b,$action);$original=Writes::prepare($b,$action);runAdvance('+1 day');resetWire();
        same('xml',$exchange($role)($origin,$command,[],1000),'Named mutation sends across midnight');
        same($original,end($seen),'Host sees original pre-seal write permit');
        runRefuses(fn()=>$exchange($role)($origin,$command,[],1000),'Named mutation cannot send twice');
        same(1,count($GLOBALS['enomWire']['forms']),'Only one physical mutation');
    });
}
$before=Budget::status()['used'];resetWire(['execFail'=>true]);
try{$transport->send(Environment::Test,ReadCommand::Balance,[],'synthetic','token',1000);throw new LogicException('Expected failed send');}catch(RuntimeException){}
same($before+1,Budget::status()['used'],'Uncertain/failed physical attempt is charged');
config(['domains.provider_send_guard'=>static function():never{throw new RuntimeException('Synthetic refusal');}]);
$before=Budget::status()['used'];resetWire();
runRefuses(fn()=>$transport->send(Environment::Test,ReadCommand::Balance,[],'synthetic','token',1000),'Host guard refusal precedes consumption');
same([],$GLOBALS['enomWire']['forms'],'Guard refusal makes zero physical sends');
same($before,Budget::status()['used'],'Unsent permit is held without an attempt');
$pending=Budget::pendingPermit();expect(is_string($pending),'Refused TEST send leaves an unconsumed permit');
$permitRow=(array)DB::table(Tables::PERMITS)->where('permit_id',$pending)->sole();
$remaining=Budget::status()['remaining'];
foreach([['namesilo','test'],['namesilo','live'],['enom','live']] as [$registrar,$environment]){
    Budget::assertPermit($pending);
    config(['domains.provider_send_guard'=>static function()use($registrar,$environment):void{
        same(null,Budget::pendingPermit(),'Unrelated '.$registrar.' '.$environment.' send exposes no stale TEST permit');
    }]);
    \Paymenter\Extensions\Servers\Domains\Services\ProviderSendGuard::beforeSend($registrar,$environment,'GetBalance');
    same($permitRow,(array)DB::table(Tables::PERMITS)->where('permit_id',$pending)->sole(),'Clearing pending keeps retained permit unchanged');
    same($remaining,Budget::status()['remaining'],'Clearing pending keeps held credit unchanged');
}
Budget::disable($policy);resetWire();
runRefuses(fn()=>$transport->send(Environment::Test,ReadCommand::Balance,[],'synthetic','token',1000),'Disabled direct reader refuses');
runRefuses(fn()=>$exchange('management')($origin,'GetBalance',[],1000),'Disabled runtime reader refuses');
same([],$GLOBALS['enomWire']['forms'],'Disabled generation makes zero physical sends');
// A disabled TEST run does not alter existing LIVE read/day behavior.
config(['domains.provider_send_guard'=>null]);resetWire();
same('xml',$transport->send(Environment::Live,ReadCommand::Balance,[],'synthetic','token',1000),'LIVE direct reads retain existing accounting policy');
same($before,Budget::status()['used'],'LIVE does not consume TEST credit');
$day=Calls::day();runAdvance('+1 day');runRefuses(fn()=>Calls::assertReservedDay($day),'LIVE day validator retains original semantics');
Budget::close($policy);app(Installation::class)->verify();
echo "Production transports: bootstrap, direct/runtime reads, original pre-seal permits, host guard, failed sends, LIVE isolation and retained install passed\n";
$nativeSuccessful=true;
