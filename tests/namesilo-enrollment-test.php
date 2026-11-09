<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/namesilo-enrollment-wire.php';
require __DIR__.'/Support/namesilo-enrollment-staff.php';
// Received malformed/mismatched replies and no response are all unconfirmed, never a proven key refusal.
$auth=new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\CredentialAuthenticator(\Paymenter\Extensions\Servers\Domains\Data\Environment::Test);
foreach(['html','wrong_operation','throw']as$mode){$GLOBALS['enrollmentMode']=$mode;
 try{$auth->authenticate(\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,['api_key'=>'synthetic-ote-key']);expect(false,'Unusable response refuses authentication');}
 catch(DomainException $e){same('Registrar key could not be confirmed.',$e->getMessage(),'Unconfirmed response does not assert no answer or bad key');}
}
$nativeSuccessful=true;
