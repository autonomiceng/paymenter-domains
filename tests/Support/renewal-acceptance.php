<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;
use Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances;

return static function ($binding, Closure $check, Closure $refresh, Closure $calls, Closure $during): void {
    $accepted = CatalogueAcceptances::load($binding);
    $original = $accepted->endings;
    $save = static function (array $endings) use ($binding): void {
        CatalogueAcceptances::save($binding, CatalogueAcceptances::load($binding)->revision, $endings);
    };
    same(RenewalCode::Admitted, $check()->code, 'Current ending acceptance admits holder');
    foreach (['renewal_safe' => false, 'renew' => [], 'absent' => null] as $flag => $value) {
        $endings = $original;
        if ($flag === 'absent') { unset($endings['com']); } else { $endings['com'][$flag] = $value; }
        $save($endings);
        same(RenewalCode::Acceptance, $check()->code, 'Revoked '.$flag.' refuses admission');
        same([], $calls(), 'Refused admission makes zero provider wire calls');
        $save($original);
        same(RenewalCode::Admitted, $check()->code, 'Restored acceptance admits fresh check');
        $save($endings);
        $before = $calls();
        same(RenewalCode::Acceptance, $refresh()->code, 'Revocation between check and refresh refuses');
        same($before, $calls(), 'Refused final refresh makes zero additional provider wire calls');
        $save($original);
        $during(static fn () => $save($endings));
        same(RenewalCode::Acceptance, $check()->code, 'Locked post-read recheck observes '.$flag.' revocation');
        expect($calls() !== [], 'Revocation occurred during actual synthetic provider reads');
        $save($original);
    }
    $withdrawn = $original;
    $withdrawn['com'] = [...$withdrawn['com'], 'registry' => false, 'register' => [], 'transfer' => []];
    $save($withdrawn);
    same(RenewalCode::Admitted, $check()->code, 'Sales withdrawal preserves holder renewal admission');
    same(RenewalCode::Admitted, $refresh()->code, 'Sales withdrawal preserves holder renewal refresh');
    $save($original);
    same(RenewalCode::Admitted, $check()->code, 'Holder remains admitted after restoring acceptance');
};
