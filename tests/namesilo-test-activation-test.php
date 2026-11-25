<?php
// Offline NS5 dependency witness; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-test-enrolled.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;
same('test',$account->environment,'Witness uses only a test account');
same('matched',app(AccountBindings::class)->configurationStatus($server->id),'Saved test key is current');
same(1,$GLOBALS['enrollmentCalls'],'Existing socket-free fake authenticates the test key once');
$refusal=null;
try{$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]);}
catch(DomainException $error){$refusal=$error->getMessage();}
same(null,$refusal,'Staff can turn on the connected NameSilo test account');
expect($account->fresh()->processing_enabled,'Reviewed Orders enables the test account');
same('OK',AccountChecklist::runtime($account->fresh())['value'],'Checklist accepts the connected test runtime');
same(1,$GLOBALS['enrollmentCalls'],'Local activation makes no provider request');
$binding=AccountBindings::binding($account->fresh());
$writes->run($review('test_transfer'),['accept'=>false]);
$writes->run($review('processing'),['enabled'=>false,'confirmed'=>true]);
$writes->run($review('processing'),['enabled'=>true,'confirmed'=>true]);
expect(!Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::accepted($binding),'Resuming TEST Orders preserves deliberate transfer Off');
$writes->run($review('test_transfer'),['accept'=>true,'verified'=>true]);
expect(Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::accepted($binding),'Reviewed TEST action activates transfers');
same(1,$GLOBALS['enrollmentCalls'],'Feature activation makes no provider request');
$nativeSuccessful=true;
