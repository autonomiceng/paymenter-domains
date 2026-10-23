<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Customer label/tone unit matrix.
declare(strict_types=1);
(function () use ($newTransfer, $owners, $clock): void {
    $op = $newTransfer('tone-matrix.com', 0, false, false);
    $base = $op->domain;
    $quiet = new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock, new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts);
    // Persist only synthetic operation inputs. Domain variants stay in memory so no lifecycle runs.
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $op->id)->update(['state' => 'succeeded']);
    $cases = [
        'active' => ['Active', 'ok'], 'adoption_pending' => ['Being set up', 'info'],
        'registration_pending' => ['Registration in progress', 'info'], 'transfer_pending' => ['Transfer in progress', 'info'],
        'registration_rejected' => ['Needs attention', 'warn'], 'transfer_rejected' => ['Needs attention', 'warn'],
        'needs_review' => ['Needs attention', 'warn'], 'expired' => ['Expired', 'bad'], 'grace' => ['Expired', 'bad'],
        'redemption' => ['In redemption', 'bad'], 'cancelled' => ['Order cancelled', 'muted'],
        'unknown_status' => ['Needs attention', 'muted'],
    ];
    foreach ($cases as $state => $expected) {
        $d = $base->fresh(); $d->status = $state;
        $dto = $quiet->present($d, false);
        same($expected, [$dto->status, $dto->tone], 'D3 status label/tone: '.$state);
    }
    $d = $base->fresh(); $d->provenance = 'adoption_pending'; $d->status = 'active';
    $dto = $quiet->present($d, false);
    same(['Being set up', 'info'], [$dto->status, $dto->tone], 'D3 adoptionPending provenance takes precedence');
    foreach (['register', 'transfer'] as $action) {
        foreach (['held', 'uncertain', 'awaiting_payment'] as $state) {
            \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $op->id)->update(['action' => $action, 'state' => $state]);
            $d = $base->fresh(); $d->status = 'needs_review';
            $expected = $state === 'awaiting_payment' ? ['Awaiting payment', 'warn']
                : ($action === 'register' ? ['Processing', 'info'] : ['Needs attention', 'warn']);
            $dto = $quiet->present($d, false);
            same($expected, [$dto->status, $dto->tone], 'D3 operation label/tone: '.$action.' '.$state);
        }
    }
    foreach (['contact_details_required', 'nameservers_required'] as $reason) {
        \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $op->id)->update(['action' => 'register', 'state' => 'held', 'safe_code' => $reason]);
        $d = $base->fresh(); $d->status = 'registration_pending';
        $dto = $owners->present($d, false);
        expect($dto->setup['required'] || $dto->setup['contacts'], 'Actionable setup fixture: '.$reason);
        same(['Needs attention', 'warn'], [$dto->status, $dto->tone], 'D3 setup overrides processing tone: '.$reason);
    }
    // Adoption expiry is a warning at the boundary, without asserting the registrar's outcome.
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $op->id)->delete();
    \Paymenter\Extensions\Servers\Domains\Services\AdoptionSchema::install();
    \Paymenter\Extensions\Servers\Domains\Services\HistoryLinkSchema::install();
    $instant = $clock->now(); $expiry = $instant->setTime(0, 0)->modify('+1 day');
    foreach (['-1 second' => ['Active', 'ok'], 'now' => ['Registry expiry reached', 'warn'], '+1 second' => ['Registry expiry reached', 'warn']] as $offset => $expected) {
        $clock->instant = $expiry->modify($offset);
        $d = $base->fresh(); $d->status = 'active'; $d->provenance = 'adopted'; $d->provider_expires_at = $expiry;
        $dto = $quiet->present($d, false);
        same($expected, [$dto->status, $dto->tone], 'D3 adopted expiry label/tone: '.$offset);
    }
    $clock->instant = $instant;
    // Remove the isolated fixture so subsequent page/count assertions retain their original cohort.
    \Illuminate\Support\Facades\DB::table('domains_domains')->where('id', $base->id)->delete();
})();
