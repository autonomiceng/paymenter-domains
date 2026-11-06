<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Native paid transfer and encrypted reconciliation require real model casts; transport is synthetic.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
$op=$prepare('revoked');
$transferServer=App\Models\Server::findOrFail($binding->serverId);
$transferServer->settings()->updateOrCreate(['key'=>'domains_namesilo_transfer'],['encrypted'=>true,'type'=>'string',
    'value'=>json_encode(['fingerprint'=>$binding->fingerprint,'enabled'=>false],JSON_THROW_ON_ERROR)]);
expect(!Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::accepted($binding),'Revoked NameSilo transfer permission refuses new work');
$r=$reconcile->run($op->id);
same('succeeded',$r->state,'A sent NameSilo transfer reconciles after account revocation');
$calls=$observe->calls;$reconcile->run($op->id);same($calls,$observe->calls,'Revoked completed transfer never replays');
echo "Revoked NameSilo transfer reconciliation passed.\n"; $nativeSuccessful=true;
