<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Customer label/tone unit matrix.
declare(strict_types=1);
(function () use ($newTransfer, $owners, $clock): void {
    $op = $newTransfer(placeholderComFixtureName('tone-matrix-placeholder.com'), 0, false, false);
    $base = $op->domain;
    $quiet = new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock, new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts);
    // Persist only synthetic operation inputs. Domain variants stay in memory so no lifecycle runs.
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $op->id)->update(['state' => 'succeeded']);
    $cases = [
        'active' => ['Active', 'ok', null], 'adoption_pending' => ['Under review', 'warn', 'Import not confirmed'],
        'registration_pending' => ['Pending registration', 'info', null], 'transfer_pending' => ['Pending transfer', 'info', null],
        'registration_rejected' => ['Failed', 'bad', 'Registration rejected'], 'transfer_rejected' => ['Failed', 'bad', 'Transfer rejected'],
        'needs_review' => ['Under review', 'warn', null], 'expired' => ['Expired', 'bad', null], 'grace' => ['Expired', 'bad', 'In grace period'],
        'redemption' => ['Redemption', 'bad', null], 'cancelled' => ['Cancelled', 'muted', null],
        'unknown_status' => ['Under review', 'warn', 'Status missing'],
    ];
    foreach ($cases as $state => $expected) {
        $d = $base->fresh(); $d->status = $state; $d->provider_expires_at = $clock->now()->modify('+200 days');
        $dto = $quiet->present($d, false);
        same($expected, [$dto->status, $dto->tone, $dto->statusReason], 'D3 status label/tone: '.$state);
        same(array_values(\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::of($state)), [$dto->status, $dto->tone, $dto->statusReason], 'OwnedDomain uses the shared status pill: '.$state);
    }
    $d = $base->fresh(); $d->provenance = 'adoption_pending'; $d->status = 'active';
    $dto = $quiet->present($d, false);
    same(['Under review', 'warn', 'Import not confirmed'], [$dto->status, $dto->tone, $dto->statusReason], 'D3 adoptionPending provenance takes precedence');
    foreach (['register', 'transfer'] as $action) {
        foreach (['held', 'uncertain', 'awaiting_payment'] as $state) {
            \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $op->id)->update(['action' => $action, 'state' => $state]);
            $d = $base->fresh(); $d->status = 'needs_review';
            $expected = $state === 'awaiting_payment' ? ['Action required', 'warn', 'Invoice not paid']
                : ($action === 'register' ? ['Pending registration', 'info', null]
                : ['Under review', 'warn', $state === 'held' ? 'Transfer on hold' : 'Transfer not confirmed']);
            $dto = $quiet->present($d, false);
            same($expected, [$dto->status, $dto->tone, $dto->statusReason], 'D3 operation label/tone: '.$action.' '.$state);
        }
    }
    foreach (['contact_details_required', 'nameservers_required'] as $reason) {
        \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $op->id)->update(['action' => 'register', 'state' => 'held', 'safe_code' => $reason]);
        $d = $base->fresh(); $d->status = 'registration_pending';
        $dto = $owners->present($d, false);
        expect($dto->setup['required'] || $dto->setup['contacts'], 'Actionable setup fixture: '.$reason);
        same(['Action required', 'warn', 'Finish setup'], [$dto->status, $dto->tone, $dto->statusReason], 'D3 setup overrides processing tone: '.$reason);
    }
    // Adoption expiry is a warning at the boundary, without asserting the registrar's outcome.
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id', $op->id)->delete();
    \Paymenter\Extensions\Servers\Domains\Services\AdoptionSchema::install();
    \Paymenter\Extensions\Servers\Domains\Services\HistoryLinkSchema::install();
    $instant = $clock->now(); $expiry = $instant->setTime(0, 0)->modify('+1 day');
    // An adoption without a record keeps renewal off, so the day before expiry asks the customer to act.
    foreach (['-1 second' => ['Action required', 'warn'], 'now' => ['Expired', 'bad'], '+1 second' => ['Expired', 'bad']] as $offset => $expected) {
        $clock->instant = $expiry->modify($offset);
        $d = $base->fresh(); $d->status = 'active'; $d->provenance = 'adopted'; $d->provider_expires_at = $expiry;
        $dto = $quiet->present($d, false);
        same($expected, [$dto->status, $dto->tone], 'D3 adopted expiry label/tone: '.$offset);
    }
    $clock->instant = $instant;
    // Remove the isolated fixture so subsequent page/count assertions retain their original cohort.
    \Illuminate\Support\Facades\DB::table('domains_domains')->where('id', $base->id)->delete();
})();
