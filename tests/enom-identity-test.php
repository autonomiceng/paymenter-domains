<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-identity.php';
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Services\IdentityRefused;
ini_set('zend.exception_ignore_args','0');
$verifier=new AccountVerifier(Environment::Test);$GLOBALS['identityCalls']=[];
$refused=static function(#[SensitiveParameter] callable $work,string $reason):void {
    try{$work();expect(false,'Identity refusal required: '.$reason);}catch(IdentityRefused $e){
        same($reason,$e->reason,'Closed identity refusal');same(null,$e->getPrevious(),'No provider cause');
        $text=(string)$e.print_r($e,true).print_r($e->getTrace(),true).json_encode($e->getTrace());
        foreach(['secret-uid','secret-token','9876.54'] as $secret){expect(!str_contains($text,$secret),'Redacted identity exception');}
    }
};
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
$GLOBALS['identityMode']='credentials';
require __DIR__.'/Support/enom-identity-staff.php';
echo "Enom identity: strict authentication, redaction, rotation and native staff flow passed.\n";$nativeSuccessful=true;
