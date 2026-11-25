<?php
// Native NameSilo transfer evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
// Native paid transfer and encrypted reconciliation require real model casts; transport is synthetic.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
require_once __DIR__.'/Support/fixture-cases.php';
// Missing or contradictory proof and the revoked account, each on its own sealed transfer of the one NameSilo
// preparation. The reset restores rows, the fake transport, the RDAP client and the clocks.
// Pin each case to the prepared read and Carbon clocks explicitly, whatever a reset restores.
$pinned = [$clock->now(), Carbon\Carbon::getTestNow(), Carbon\CarbonImmutable::getTestNow()];
$pin = static function () use ($clock, $pinned): void { [$clock->instant, $native, $immutable] = $pinned; Carbon\Carbon::setTestNow($native); Carbon\CarbonImmutable::setTestNow($immutable); };
nativeFixtureCases([
    'refusals' => static function () use ($pin, $unresolved): void {
        $pin();
        $unresolved([
            'owner', 'nocharge', 'duplicate',
            'late', 'credited', 'unknowncharge', 'paged', 'nohistory', 'lag',
            'inactive', 'auto', 'privacy', 'short',
        ]);
    },
    'revoked' => static function () use ($pin, $prepare, $reconcile, $observe, &$binding): void {
        $pin();
        $op=$prepare('revoked');
        $transferServer=App\Models\Server::findOrFail($binding->serverId);
        $transferServer->settings()->updateOrCreate(['key'=>'domains_namesilo_transfer'],['encrypted'=>true,'type'=>'string',
            'value'=>json_encode(['fingerprint'=>$binding->fingerprint,'enabled'=>false],JSON_THROW_ON_ERROR)]);
        expect(!Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::accepted($binding),'Revoked NameSilo transfer permission refuses new work');
        $r=$reconcile->run($op->id);
        same('succeeded',$r->state,'A sent NameSilo transfer reconciles after account revocation');
        $calls=$observe->calls;$reconcile->run($op->id);same($calls,$observe->calls,'Revoked completed transfer never replays');
    },
// The transfer RDAP client keeps the bootstrap it read; the reset returns it to the prepared value.
], compact('clock', 'state', 'observe', 'claims', 'reconcile', 'readBindings', 'registry', 'rdap'));
echo "NameSilo transfer refusal and revoked reconciliation evidence passed\n";
$nativeSuccessful = true;
