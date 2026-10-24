<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomTestCallLimit,EnomTestCallSchema,EnomTestCalls,Installation,RegistrarRegistry,Rdap,RenewalAcceptance,Runtime,AccountReadBudget,RuntimePorts,TransferPrecheck,WriteAcceptance,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
require_once __DIR__.'/FakeRegistrar.php';
ini_set('zend.exception_ignore_args','0');
$binding=new AccountBinding('enom',1,Environment::Test,'synthetic-uid');$bindings=new AccountBindings(new RegistrarRegistry);
$clock=new FakeClock(new DateTimeImmutable('2026-10-18T12:00:00Z'));$count=0;
app()->instance(Clock::class,$clock);app()->instance(Installation::class,new Installation);EnomTestCallSchema::install();
config(['domains.enom_test_daily_call_cap'=>1000]);
expect(app()->bound(Installation::class),'Synthetic Enom fixture binds the retained installation authority');
expect(\Illuminate\Support\Facades\Schema::hasTable(EnomTestCallSchema::TABLE),'Synthetic Enom fixture installs the retained TEST counter');
same('2026-10-18',\Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::reserve(Environment::Test),'Synthetic Enom TEST reservation uses the bound UTC clock');
$credentials=static function()use(&$count){$count++;return ['uid'=>'synthetic-uid','token'=>'SYNTHETIC-TOKEN'];};
$make=static fn($b,$get)=>RuntimeFactory::make($b,$bindings,$clock,new WriteFeatures,new WriteAcceptance($b),new RenewalAcceptance($b),new TransferPrecheck(new Rdap(static fn()=>null)),$get);
app()->instance(Runtime::class,new Runtime);app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new AccountReadBudget($clock));
resetWire();$factory=$make($binding,$credentials);$composition=$factory();
same(array_keys(RuntimePorts::TYPES),array_keys($composition),'Seven pure runtime roles');same(0,$count,'Construction never decrypts');same(0,$GLOBALS['enomWire']['inits'],'Construction never opens cURL');
// Reflection is test-only: the shipping factory exposes no exchange or generic sender.
$wire=(new ReflectionFunction($factory))->getStaticVariables()['wire'];
$exchange=static function($w,$role):Closure {
    $inner=(new ReflectionMethod($w,'exchange'))->invoke($w,$role);
    $binding=(new ReflectionProperty($w,'binding'))->getValue($w);
    return static function($url,$command,#[SensitiveParameter] array $parameters,$timeoutMs)use($binding,$inner):string {
        if($command!=='Purchase'||$binding->environment!==Environment::Test||\Illuminate\Support\Facades\DB::transactionLevel()!==0){return $inner($url,$command,$parameters,$timeoutMs);}
        try{return \Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::withPurchase($binding,function()use($binding,$inner,$url,$command,$parameters,$timeoutMs):string {
                \Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::reservePurchase($binding);
                return $inner($url,$command,$parameters,$timeoutMs);
            });}catch(\Throwable){throw new RuntimeException('Enom exchange failed.');}
    };
};
$origin='https://resellertest.enom.com/interface.asp';
$deny=static function(#[SensitiveParameter] callable $call,string $label)use(&$count):void {
    resetWire();$before=$count;
    try{$call();throw new LogicException('Expected exchange refusal: '.$label);}catch(RuntimeException $e){
        same('Enom exchange failed.',$e->getMessage(),'Sanitized refusal: '.$label);same(null,$e->getPrevious(),'No unsafe cause');
        $trace=(string)$e.print_r($e->getTrace(),true).json_encode($e->getTrace());
        foreach(['SYNTHETIC-TOKEN','synthetic-uid','CONTACT-SENTINEL','EPP-SENTINEL'] as $secret){expect(!str_contains($trace,$secret),'No secret trace: '.$label);}
    }
    same(0,$GLOBALS['enomWire']['inits'],'Refused before cURL: '.$label);same($before,$count,'Refused before credentials: '.$label);
};

resetWire();$beforeCount=$count;
Illuminate\Support\Facades\DB::transaction(static function()use($exchange,$wire,$origin){
    try{$exchange($wire,'registration')($origin,'Purchase',[],1000);throw new LogicException('Expected transaction refusal');}
    catch(RuntimeException $e){same('Enom exchange failed.',$e->getMessage(),'Transaction refusal sanitized');}
});
same(0,$GLOBALS['enomWire']['inits'],'Transaction refusal before cURL');same($beforeCount,$count,'Transaction refusal before credentials');
app()->offsetUnset(Runtime::class);$deny(fn()=>$exchange($wire,'registration')($origin,'Purchase',[],1000),'missing shared limiter');app()->instance(Runtime::class,new Runtime);
foreach(['https://reseller.enom.com/interface.asp',$origin.'/',$origin.'?x=1','http://resellertest.enom.com/interface.asp','https://evil.test/interface.asp'] as $bad){$deny(fn()=>$exchange($wire,'registration')($bad,'Purchase',[],1000),'origin');}
foreach([0,25001] as $bad){$deny(fn()=>$exchange($wire,'registration')($origin,'Purchase',[],$bad),'timeout');}
foreach(['Purchase','TP_CreateOrder','Extend'] as $bad){$deny(fn()=>$exchange($wire,'management')($origin,$bad,[],1000),'management cannot acquire');}
foreach(['registration'=>'Extend','transfer'=>'Purchase','renewal'=>'Purchase','unknown'=>'Purchase'] as $role=>$bad){$deny(fn()=>$exchange($wire,$role)($origin,$bad,[],1000),'role');}
foreach([['uid'=>'CONTACT-SENTINEL'],['PW'=>'CONTACT-SENTINEL'],['command'=>'Purchase'],['ResponseType'=>'text'],['UseCreditCard'=>'yes'],['RenewFlag'=>1],['SLD'=>'one','sld'=>'two'],['Bad-Key'=>'one'],['A'=>[]],['A'=>1.25],['A'=>str_repeat('x',4097)],array_fill_keys(array_map(fn($i)=>'a'.$i,range(1,513)),'x'),['ChargeAmount'=>'1'],['EmailEPP'=>'false'],[0=>'x'],['RenewName'=>'yes'],['UseWireTransfer'=>'yes'],['OverrideOrder'=>1],['DeleteOrderDetail'=>1],['PremiumDomain'=>1],['CustomerSuppliedPrice'=>'1'],['CreditCardNumber'=>'x'],['CreditCardExpMonth'=>'1'],['CreditCardExpYear'=>'2030'],['CVV2'=>'x'],['CCName'=>'x']] as $bad){
    $deny(fn()=>$exchange($wire,'registration')($origin,'Purchase',$bad,1000),'parameters');
}
foreach(['0','synthetic-keylog'] as $value){putenv('SSLKEYLOGFILE='.$value);$deny(fn()=>$exchange($wire,'registration')($origin,'Purchase',[],1000),'TLS keylog');putenv('SSLKEYLOGFILE');}
foreach([Environment::Test,Environment::Live] as $environment){
    $b=new AccountBinding('enom',1,$environment,'synthetic-uid');$f=$make($b,$credentials);$w=(new ReflectionFunction($f))->getStaticVariables()['wire'];
    $url=$environment===Environment::Test?$origin:'https://reseller.enom.com/interface.asp';resetWire();
    same('xml',$exchange($w,'registration')($url,'Purchase',['SLD'=>'example','TLD'=>'com'],25000),'Complete body');
    $options=$GLOBALS['enomWire']['options'];same($url,$options[CURLOPT_URL],'Fixed environment URL');same(['Content-Type: application/x-www-form-urlencoded','Accept: application/xml'],$options[CURLOPT_HTTPHEADER],'Explicit XML form headers');
    same(['Command'=>'Purchase','UID'=>'synthetic-uid','PW'=>'SYNTHETIC-TOKEN','ResponseType'=>'XML','SLD'=>'example','TLD'=>'com'],$GLOBALS['enomWire']['forms'][0],'Credentialed POST');
    foreach([CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT_MS=>8000,CURLOPT_TIMEOUT_MS=>25000,CURLOPT_VERBOSE=>false,CURLOPT_HEADER=>false,CURLOPT_FRESH_CONNECT=>true,CURLOPT_FORBID_REUSE=>true,CURLOPT_LOW_SPEED_LIMIT=>32,CURLOPT_LOW_SPEED_TIME=>10] as $key=>$value){same($value,$options[$key],'Safe wire option '.$key);}
    same(1,$GLOBALS['enomWire']['closed'],'Handle released');
    resetWire();(new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\CurlTransport)->send($environment,Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Info,['SLD'=>'example','TLD'=>'com'],'synthetic-uid','SYNTHETIC-TOKEN',25000);
    $readOptions=$GLOBALS['enomWire']['options'];foreach([CURLOPT_POSTFIELDS,CURLOPT_WRITEFUNCTION] as $different){unset($options[$different],$readOptions[$different]);}
    same($readOptions,$options,'Read and protocol cURL safeguards remain identical');
}
foreach(['registration'=>['Purchase','ModifyNS','GetDNS'],'transfer'=>['TP_CreateOrder'],'renewal'=>['Extend','UpdateExpiredDomains'],
    'management'=>array_merge(array_map(fn($c)=>$c->value,Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ManagementCommand::cases()),['GetDomainInfo','GetDNS','GetContacts','GetRegLock','GetWPPSInfo','GetRenew','GetHosts','GetDomainSRVHosts','GetDnsSec','CheckNSStatus','GetBalance','GetOrderDetail'])] as $role=>$commands){
    foreach($commands as $command){resetWire();same('xml',$exchange($wire,$role)($origin,$command,[],1200),'Reviewed protocol command');same(1200,$GLOBALS['enomWire']['options'][CURLOPT_CONNECTTIMEOUT_MS],'Shortened connect timeout');}
}
$savedClock=$clock->now();$clock->instant=new DateTimeImmutable('2026-10-18T23:59:59Z');$oldDay=EnomTestCalls::day();
$oldUsed=(int)(\Illuminate\Support\Facades\DB::table(EnomTestCallSchema::TABLE)->where('day',$oldDay)->value('used')??0);$crossed=false;
\Illuminate\Support\Facades\DB::listen(static function($query)use(&$crossed,$clock):void {
    if(!$crossed&&str_contains($query->sql,EnomTestCallSchema::TABLE)){$crossed=true;$clock->instant=$clock->instant->modify('+1 second');}
});
resetWire();try{$exchange($wire,'management')($origin,'GetBalance',[],25000);throw new LogicException('A TEST read cannot send on yesterday’s reservation');}catch(EnomTestCallLimit){}
same([],$GLOBALS['enomWire']['forms'],'UTC rollover refusal stays before curl_exec');
same($oldUsed+1,(int)\Illuminate\Support\Facades\DB::table(EnomTestCallSchema::TABLE)->where('day',$oldDay)->value('used'),'Rollover read unit remains charged to reservation day');
same(0,(int)(\Illuminate\Support\Facades\DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used')??0),'Rollover refusal does not charge a second day');
$clock->instant=$savedClock;$beforeUsed=(int)\Illuminate\Support\Facades\DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used');$slow=false;
\Illuminate\Support\Facades\DB::listen(static function($query)use(&$slow):void {
    if(!$slow&&str_contains($query->sql,EnomTestCallSchema::TABLE)){$slow=true;$GLOBALS['enomWire']['nanoseconds']+=26000000000;}
});
resetWire();try{$exchange($wire,'management')($origin,'GetBalance',[],25000);throw new LogicException('Counter latency cannot extend the TEST deadline');}catch(EnomTestCallLimit){}
same([],$GLOBALS['enomWire']['forms'],'Counter delay beyond deadline refuses before curl_exec');
same($beforeUsed+1,(int)\Illuminate\Support\Facades\DB::table(EnomTestCallSchema::TABLE)->where('day',EnomTestCalls::day())->value('used'),'Timed-out reservation remains spent');
foreach([['initFail'=>true],['configFail'=>true],['execFail'=>true],['status'=>302],['status'=>500],['timeout'=>true],['chunks'=>[str_repeat('x',1048576),'x']]] as $failure){
    resetWire($failure);
    try{$exchange($wire,'registration')($origin,'Purchase',['Contact'=>'CONTACT-SENTINEL','AuthInfo1'=>'EPP-SENTINEL'],1200);throw new LogicException('Expected wire failure');}
    catch(RuntimeException $e){same('Enom exchange failed.',$e->getMessage(),'Failure sanitized');$trace=(string)$e.print_r($e->getTrace(),true);foreach(['SYNTHETIC-TOKEN','synthetic-uid','CONTACT-SENTINEL','EPP-SENTINEL'] as $secret){expect(!str_contains($trace,$secret),'Transport trace redacted');}same(null,$e->getPrevious(),'No unsafe cause');}
    expect(count($GLOBALS['enomWire']['forms'])<=1,'No retries');same($failure['initFail']??false?0:1,$GLOBALS['enomWire']['closed'],'Failure closes handle');
}
resetWire(['chunks'=>[str_repeat('x',1048576)]]);same(1048576,strlen($exchange($wire,'registration')($origin,'Purchase',[],1200)),'Exact body limit');
foreach([[],['uid'=>false,'token'=>'SYNTHETIC-TOKEN'],['uid'=>'','token'=>'SYNTHETIC-TOKEN'],['uid'=>'synthetic-uid','token'=>false],['uid'=>'synthetic-uid','token'=>''],['uid'=>'synthetic-uid','token'=>str_repeat('x',4097)],['uid'=>'synthetic-uid','token'=>"bad\n"],['uid'=>'wrong-uid','token'=>'SYNTHETIC-TOKEN']] as $bad){
    $f=$make($binding,static fn()=>$bad);$w=(new ReflectionFunction($f))->getStaticVariables()['wire'];resetWire();
    try{$exchange($w,'registration')($origin,'Purchase',[],1200);throw new LogicException('Expected credential refusal');}catch(RuntimeException){}
    same(0,$GLOBALS['enomWire']['inits'],'Bad credentials never open cURL');
}
$f=$make($binding,static function(){ $GLOBALS['enomWire']['nanoseconds']=2000000000;return ['uid'=>'synthetic-uid','token'=>'SYNTHETIC-TOKEN']; });
$w=(new ReflectionFunction($f))->getStaticVariables()['wire'];resetWire();
try{$exchange($w,'registration')($origin,'Purchase',[],1200);throw new LogicException('Expected elapsed deadline');}catch(RuntimeException){}
same(0,$GLOBALS['enomWire']['inits'],'Credential time cannot extend deadline');
resetWire();$exchange($w,'registration')($origin,'Purchase',[],5000);same(3000,$GLOBALS['enomWire']['options'][CURLOPT_TIMEOUT_MS],'Credential time shortens total timeout');
try{$make(new AccountBinding('namesilo',2,Environment::Test,'other'),$credentials);throw new LogicException('Expected wrong registrar');}catch(InvalidArgumentException){}
ob_start();var_dump($wire,$factory,$composition);$dump=ob_get_clean();expect(!str_contains($dump,'SYNTHETIC-TOKEN'),'Factory diagnostics have no credentials');
foreach([fn()=>serialize($wire),fn()=>clone $wire] as $call){try{$call();throw new RuntimeException('Expected server-only refusal');}catch(LogicException){}}
echo "Enom private exchange: role/origin/credential/deadline guards, bounded cURL, no retry and redaction passed.\n";
