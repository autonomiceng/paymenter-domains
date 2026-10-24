<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-test-counter.php';
require __DIR__.'/Support/enom-identity.php';
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Services\{EnomTestCallLimit,IdentityRefused,RegistrarRegistry};
ini_set('zend.exception_ignore_args','0');
$verifier=new AccountVerifier(Environment::Test);$GLOBALS['identityCalls']=[];
$refused=static function(#[SensitiveParameter] callable $work,string $reason):void {
    try{$work();expect(false,'Identity refusal required: '.$reason);}catch(IdentityRefused $e){
        same($reason,$e->reason,'Closed identity refusal');same(null,$e->getPrevious(),'No provider cause');
        $text=(string)$e.print_r($e,true).print_r($e->getTrace(),true).json_encode($e->getTrace());
        foreach(['secret-uid','secret-token','9876.54'] as $secret){expect(!str_contains($text,$secret),'Redacted identity exception');}
    }
};
$parser=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ResponseParser;
$text='<errors><Err1>Bad User name or Password</Err1></errors>';
$code='<responses><response><ResponseNumber>304155</ResponseNumber></response></responses>';
foreach ([$text,$code] as $signal) {
    $reply=$parser->parse(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Balance,
        \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\identityXml($signal,'1'));
    same(['rejected','identity_credentials_refused'],[$reply->status,$reply->code],'Each credential-refusal signal independently classifies');
}
foreach ([[$text,'0'],[$code,'0'],[$text,'2'],[$code,'2'],['<errors><Err1>Unrelated failure</Err1></errors>','1'],
    ['<responses><response><ResponseNumber>304156</ResponseNumber></response></responses>','1']] as [$signal,$count]) {
    $reply=$parser->parse(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand::Balance,
        \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\identityXml($signal,$count));
    expect($reply->code!=='identity_credentials_refused','Unrelated errors and non-single error counts do not claim credential refusal');
}
$credentials=['uid'=>'secret-uid','token'=>'secret-token'];
same('secret-uid',$verifier->verify(Environment::Test,$credentials),'Authenticated UID is canonical identity');
foreach([['uid'=>'wrong-uid','token'=>'secret-token'],['uid'=>'secret-uid','token'=>'wrong-token']] as $bad){$refused(fn()=>$verifier->verify(Environment::Test,$bad),'identity_credentials_refused');}
$before=count($GLOBALS['identityCalls']);$refused(fn()=>$verifier->verify(Environment::Live,$credentials),'identity_environment_refused');same($before,count($GLOBALS['identityCalls']),'Wrong bound environment makes no call');
$live=new AccountVerifier(Environment::Live);$refused(fn()=>$live->verify(Environment::Live,$credentials),'identity_credentials_refused');same($before+1,count($GLOBALS['identityCalls']),'Wrong-host credentials never fall back');
foreach(['html','partial','done','errors','missing_balance','bad_balance','timeout'] as $mode){
    $GLOBALS['identityMode']=$mode;$before=count($GLOBALS['identityCalls']);
    $refused(fn()=>$verifier->verify(Environment::Test,$credentials),'identity_unavailable');same($before+1,count($GLOBALS['identityCalls']),'One bounded attempt, no retry');
}
$GLOBALS['identityMode']='empty_account';same('secret-uid',$verifier->verify(Environment::Test,$credentials),'Zero funds and no domains still authenticate; no contact/domain substitute');
$before=count($GLOBALS['identityCalls']);config(['domains.enom_test_daily_call_cap'=>0]);
$registry=new RegistrarRegistry;$registry->register('enom',['uid','token'],$verifier,static fn()=>throw new LogicException('Reader is unused.'),null,true);
try{$registry->verify('enom',1,Environment::Test,$credentials);throw new LogicException('Identity cap refusal must remain typed through registry');}catch(EnomTestCallLimit){}
same($before,count($GLOBALS['identityCalls']),'Identity cap refuses before transport send');config(['domains.enom_test_daily_call_cap'=>50]);
$GLOBALS['identityMode']='credentials';
require __DIR__.'/Support/enom-identity-staff.php';
echo "Enom identity: strict authentication, redaction, rotation and native staff flow passed.\n";$nativeSuccessful=true;
