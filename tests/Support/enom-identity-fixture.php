<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
// Installation now boots Runtime: declare its identity-only deployment before the retained counter fixture.
config(['domains.enom_identity.environment'=>'test','domains.namesilo_account.environment'=>'']);
require __DIR__.'/enom-test-counter.php';
require __DIR__.'/enom-identity.php';
$GLOBALS['identityCalls']=[];$GLOBALS['identityMode']='credentials';
