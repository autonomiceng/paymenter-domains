<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/enom-wire.php';
require __DIR__.'/Support/enom-exchange.php';
$method=new ReflectionMethod(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::class,'registrationAcceptance');
$source=implode('',array_slice(file($method->getFileName()),$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1));
expect(!str_contains($source,"RegistrationCommand::Purchase->value==='Purchase'"),'Environment permission must not claim constant command comparison as protocol evidence');
same(['route'=>'https://resellertest.enom.com/interface.asp','batch_route'=>true,'interval_seconds'=>25],
    \Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding),'TEST acceptance reports environment permission');
$live=new \Paymenter\Extensions\Servers\Domains\Data\AccountBinding('enom',1,\Paymenter\Extensions\Servers\Domains\Data\Environment::Live,'synthetic-uid');
same(false,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($live)['batch_route'],'Unaccepted live route stays off');
$nativeSuccessful=true;
