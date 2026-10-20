<?php
// Simultaneous native filter discriminators; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Carbon\{Carbon, CarbonImmutable};
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

/** Every lookalike has its own current, identity-matching holding and request. */
function staffFilterLookalikes(Domain $seed, bool $expiry = false): array
{
    $cases = [
        'held' => ['transfer', 'held', 'paid_invoice', 'transfer_needs_action'],
        'contact_hold' => ['register', 'held', 'paid_invoice', 'contact_details_required'],
        'uncertain' => ['transfer', 'uncertain', 'paid_invoice', null],
        'pending' => ['transfer', 'pending', 'paid_invoice', null],
        'running' => ['transfer', 'running', 'paid_invoice', null],
        'queued' => ['transfer', 'queued', 'paid_invoice', null],
        'awaiting_payment' => ['transfer', 'awaiting_payment', 'paid_invoice', null],
        'succeeded' => ['transfer', 'succeeded', 'paid_invoice', null],
        'provider_rejected' => ['transfer', 'rejected', 'paid_invoice', 'transfer_provider_rejected'],
        'timeout' => ['transfer', 'rejected', 'paid_invoice', 'transfer_code_timeout'],
        'registration_rejected' => ['register', 'rejected', 'paid_invoice', 'preflight_failed'],
        'renewal_rejected' => ['renew', 'rejected', 'paid_invoice', 'renewal_cost'],
        'complimentary_timeout' => ['transfer', 'rejected', 'complimentary', 'transfer_code_timeout'],
        'contacts_rejected' => ['contacts', 'rejected', 'paid_invoice', 'transfer_code_timeout'],
        'reasonless' => ['register', 'rejected', 'paid_invoice', null],
        'credited_timeout' => ['transfer', 'rejected', 'paid_invoice', 'transfer_code_timeout'],
    ];
    if ($expiry) {
        foreach (['yesterday', 'today', 'window_end', 'after_window'] as $key) {
            $cases[$key] = ['transfer', 'succeeded', 'paid_invoice', null];
        }
    }
    $rows = [];
    foreach ($cases as $key => [$action, $state, $basis, $reason]) {
        $label = 'filter-'.str_replace('_', '-', $key);
        $days = match ($key) { 'yesterday' => -1, 'today' => 0, 'window_end' => 30, 'after_window' => 31, default => 120 };
        $domain = $seed->replicate()->fill(['domain' => $label.'.example.com', 'sld' => $label,
            'service_id' => 1000 + count($rows), 'status' => 'active',
            'provider_expires_at' => CarbonImmutable::today()->addDays($days)->format('Y-m-d')]);
        $domain->save();
        $operation = $seed->operations()->sole()->replicate()->fill(['domain_id' => $domain->id,
            'service_id' => $domain->service_id, 'authority_generation' => $domain->authority_generation,
            'operation_key' => $label, 'action' => $action, 'state' => $state, 'funding_basis' => $basis,
            'safe_code' => $reason, 'attempt' => [], 'policy' => [], 'payload' => [], 'cost' => [],
            'funding' => $key === 'credited_timeout' ? ['refund' => ['to' => 'account_credit']] : []]);
        $operation->save();
        $rows[$key] = ['domain' => $domain, 'operation' => $operation];
    }
    return $rows;
}

/** Exercise Filament's deferred Apply action and exact membership in the same fixture population. */
function staffAssertLookalikeFilter($viewer, string $page, string $filter, mixed $value, array $rows, string $kind, array $keys): void
{
    $yes = $no = [];
    foreach ($rows as $key => $pair) {
        if (in_array($key, $keys, true)) { $yes[] = $pair[$kind]; } else { $no[] = $pair[$kind]; }
    }
    $component = Livewire::actingAs($viewer)->test($page)->searchTable('filter-')->filterTable($filter, $value);
    $component->set('tableDeferredFilters', $component->get('tableFilters'))->call('applyTableFilters')
        ->assertCanSeeTableRecords($yes)->assertCanNotSeeTableRecords($no)->assertCountTableRecords(count($yes));
}

function staffDomainFilterLookalikes($viewer, Domain $seed): void
{
    $mutableNow = Carbon::getTestNow(); $immutableNow = CarbonImmutable::getTestNow();
    Carbon::setTestNow('2026-10-20 12:00:00'); CarbonImmutable::setTestNow('2026-10-20 12:00:00');
    DB::beginTransaction();
    try {
        $rows = staffFilterLookalikes($seed, true);
        Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('filter-')
            ->assertCanSeeTableRecords(array_column($rows, 'domain'))->assertCountTableRecords(count($rows));
        foreach ([
            ['registrar_rejected', true, ['provider_rejected']],
            ['expiring', '30', ['today', 'window_end']],
            ['expired', true, ['yesterday']],
            ['held', true, ['held', 'contact_hold']],
            ['unresolved', true, ['uncertain', 'pending', 'running']],
            // This SQL queue includes credited rows; encrypted refund evidence is interpreted per row.
            ['credit_review', true, ['timeout', 'registration_rejected', 'renewal_rejected', 'reasonless', 'credited_timeout']],
        ] as [$filter, $value, $keys]) {
            staffAssertLookalikeFilter($viewer, ListDomains::class, $filter, $value, $rows, 'domain', $keys);
        }
    } finally {
        DB::rollBack(); Carbon::setTestNow($mutableNow); CarbonImmutable::setTestNow($immutableNow);
    }
    echo "Domain lookalike filters: rejection reason, inclusive expiry window, expired, holds and credit review passed.\n";
}

function staffOperationFilterLookalikes($viewer, Domain $seed): void
{
    DB::beginTransaction();
    try {
        $rows = staffFilterLookalikes($seed);
        Livewire::actingAs($viewer)->test(ListOperations::class)->searchTable('filter-')
            ->assertCanSeeTableRecords(array_column($rows, 'operation'))->assertCountTableRecords(count($rows));
        foreach ([
            ['held', true, ['held', 'contact_hold']],
            ['unresolved', true, ['uncertain', 'pending', 'running']],
            ['failed_transfer', true, ['provider_rejected', 'timeout', 'complimentary_timeout', 'credited_timeout']],
            ['credit_review', true, ['timeout', 'registration_rejected', 'renewal_rejected', 'reasonless', 'credited_timeout']],
        ] as [$filter, $value, $keys]) {
            staffAssertLookalikeFilter($viewer, ListOperations::class, $filter, $value, $rows, 'operation', $keys);
        }
        foreach (['held', 'uncertain', 'pending', 'running', 'rejected', 'succeeded'] as $state) {
            $keys = match ($state) {
                'held' => ['held', 'contact_hold'],
                'rejected' => ['provider_rejected', 'timeout', 'registration_rejected', 'renewal_rejected',
                    'complimentary_timeout', 'contacts_rejected', 'reasonless', 'credited_timeout'],
                default => [$state],
            };
            staffAssertLookalikeFilter($viewer, ListOperations::class, 'state', [$state], $rows, 'operation', $keys);
        }
        staffAssertLookalikeFilter($viewer, ListOperations::class, 'state', ['held', 'uncertain'], $rows,
            'operation', ['held', 'contact_hold', 'uncertain']);
    } finally { DB::rollBack(); }
    echo "Operation lookalike filters: holds, unresolved states, failed-transfer action, credit reasons and status selection passed.\n";
}
