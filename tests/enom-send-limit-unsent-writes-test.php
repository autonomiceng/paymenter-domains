<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// A send-limit refusal proves a write unsent only while its scope is armed and not consumed.
// Management runs here through the production exchange; renewal is in enom-test-renewal-guards-test.php.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/WireLimit.php';
set_exception_handler(static function (Throwable $error): never {fwrite(STDERR,$error::class.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
\Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire();
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,EnomTestManagement,EnomTestSendLimit,EnomTestSendLimitSchema,ManagementEvidence,Rdap,RenewalAcceptance,Runtime,TransferPrecheck,WriteAcceptance,WriteFeatures};
use function Paymenter\Extensions\Servers\Domains\Registrars\Enom\resetWire;
app()->instance(Clock::class,$clock);EnomTestSendLimitSchema::install();
// The holding is made under the default policy; the TEST policy applies only to the runs.
[$d,$original]=$holding(placeholderComFixtureName('send-limit-unsent-placeholder.com'));$binding=$original->capabilities()->binding;
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.management'=>true,
    'domains.enom_test_sends_per_day'=>1000,'domains.enom_test_sends_per_minute'=>100000]);
$setting=$product->server->settings()->firstOrNew(['key'=>EnomTestManagement::KEY]);$setting->encrypted=true;$setting->type='string';
$setting->value=json_encode(['fingerprint'=>$binding->fingerprint,...EnomTestManagement::protocol()]);$setting->save();
app()->instance(Runtime::class,new Runtime);app()->instance(ReadLimiter::class,new WireLimit(1000));
$factory=RuntimeFactory::make($binding,$bindings,$clock,new WriteFeatures(management:true),new WriteAcceptance($binding),new RenewalAcceptance($binding),
    new TransferPrecheck(new Rdap(static fn()=>null)),static fn()=>['uid'=>'account-enom','token'=>'synthetic-offline-token'],managementAccepted:true,testManagement:true);
['management'=>$engine,'management_protocol'=>$driver]=$factory();
$reset=static function()use($exchange):void {
    Cache::flush();
    resetWire(['reply'=>static function(array $form)use($exchange):string {
        $command=$form['Command'];unset($form['Command'],$form['UID'],$form['PW'],$form['ResponseType']);
        return $exchange('https://resellertest.enom.com/interface.asp',$command,$form,25000);
    }]);
};
$commands=static fn():array=>array_column($GLOBALS['enomWire']['forms'],'Command');
$sends=static fn():int=>DB::table(EnomTestSendLimitSchema::SENDS)->count();
$recent=static fn():int=>EnomTestSendLimit::usage('account-enom')['day'];
$ask=static fn(string $action,array $payload)=>$engine->request($user,$d->id,$action,$payload,bin2hex(random_bytes(32)),$driver);

// (a) The limit refuses the write itself: armed and not consumed, so the write is proven unsent.
$reset();$op=$ask('nameservers',['nameservers'=>['ns3.example.com','ns4.example.com']]);
config(['domains.enom_test_sends_per_day'=>$recent()+1]);$before=$sends();$op=$engine->run($op->id,$driver)->fresh();
same(['GetDomainInfo'],$commands(),'The refused write never reaches cURL');same($before+1,$sends(),'Only the preparation read is counted');
same(['held','enom_test_management_call_cap'],[$op->state,$op->safe_code],'A limit refusal before the write is held');
same(['sealed','definitely_unsent',true],[$op->attempt['seal'],$op->attempt['send_state'],$op->attempt['management_test_unsent']??null],'The sealed write keeps durable unsent proof');
expect(ManagementEvidence::idle($op),'A proven unsent write does not block the owner');
config(['domains.enom_test_sends_per_day'=>1000]);$engine->retire($user,$op->id);
$reset();$retry=$ask('nameservers',['nameservers'=>['ns3.example.com','ns4.example.com']]);
same('succeeded',$engine->run($retry->id,$driver)->state,'The owner retries with a fresh request');same(1,count(array_keys($commands(),'ModifyNS')),'The retry sends the write once');
same(['ns3.example.com','ns4.example.com'],$state->ns,'The provider holds the requested nameservers');

// (b) The write was sent, then the same run's next send is refused: consumed is sticky, so never unsent.
$reset();$op=$ask('lock',['locked'=>false]);
config(['domains.enom_test_sends_per_day'=>$recent()+2]);$op=$engine->run($op->id,$driver)->fresh();
same(['GetDomainInfo','SetRegLock'],$commands(),'The write is sent and the following read is refused');
same(['uncertain','sealed','possibly_sent'],[$op->state,$op->attempt['seal'],$op->attempt['send_state']],'A refusal after a sent write stays uncertain');
expect(!isset($op->attempt['management_test_unsent'])&&!ManagementEvidence::idle($op),'A sent write never gets unsent proof');
$engine->run($op->id,$driver);same(['GetDomainInfo','SetRegLock'],$commands(),'An uncertain write is never resent');
config(['domains.enom_test_sends_per_day'=>1000]);$reset();
same('succeeded',$engine->reconcile($op->id,$driver)->state,'Reads alone confirm the sent write');expect(!in_array('SetRegLock',$commands(),true),'Confirmation never resends');

// (b) A typed refusal raised after the write was handed to cURL is also never unsent.
$reset();$op=$ask('lock',['locked'=>true]);
$GLOBALS['enomWire']['during']=static function(array $form):void {if($form['Command']==='SetRegLock'){throw new EnomTestCallLimit;}};
$op=$engine->run($op->id,$driver)->fresh();
same(['uncertain','possibly_sent'],[$op->state,$op->attempt['send_state']],'A refusal after cURL received the write stays uncertain');
expect(!isset($op->attempt['management_test_unsent']),'The consumed scope gives no unsent marker');same(1,count(array_keys($commands(),'SetRegLock')),'One write, no resend');
app()->offsetUnset(Runtime::class);
echo "Send-limit refusals: management write unsent only before cURL; after a sent write, uncertain and never resent.\n";
$nativeSuccessful=true;
