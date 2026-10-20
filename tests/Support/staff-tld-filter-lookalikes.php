<?php
// Simultaneous native selector discriminators; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/accounts.php';
require_once __DIR__.'/staff-filter-lookalikes.php';
use Livewire\Livewire;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Domains\Models\Tld;

function staffTldFilterLookalikes($viewer, array $tlds): void
{
    DB::beginTransaction();
    try {
        $second = fixtureAccount((int)\App\Models\Server::max('id') + 1, 'enom', 'selector-second-enom');
        $bindings = [
            'first_enom' => $tlds[0]->only(['server_id', 'registrar_key', 'account_fingerprint']),
            'second_enom' => $second,
            'namesilo' => $tlds[1]->only(['server_id', 'registrar_key', 'account_fingerprint']),
        ];
        $rows = [];
        foreach ($bindings as $key => $binding) {
            // Identical endings ensure search/sort cannot distinguish these account-bound rows.
            $rows[$key] = ['tld' => Tld::create([...$binding, 'tld' => 'filter-selectors.example.com'])];
        }
        Livewire::actingAs($viewer)->test(ListTlds::class)->searchTable('filter-')
            ->assertCanSeeTableRecords(array_column($rows, 'tld'))->assertCountTableRecords(3);
        foreach ($bindings as $key => $binding) {
            staffAssertLookalikeFilter($viewer, ListTlds::class, 'server_id', (string)$binding['server_id'], $rows, 'tld', [$key]);
        }
        foreach (['enom' => ['first_enom', 'second_enom'], 'namesilo' => ['namesilo']] as $registrar => $keys) {
            staffAssertLookalikeFilter($viewer, ListTlds::class, 'registrar_key', $registrar, $rows, 'tld', $keys);
        }
        foreach (['enom' => ['second_enom'], 'namesilo' => []] as $registrar => $keys) {
            $page = Livewire::actingAs($viewer)->test(ListTlds::class)->searchTable('filter-')
                ->filterTable('server_id', (string)$second['server_id'])->filterTable('registrar_key', $registrar);
            $page->set('tableDeferredFilters', $page->get('tableFilters'))->call('applyTableFilters')
                ->assertCanSeeTableRecords(array_map(fn($key) => $rows[$key]['tld'], $keys))
                ->assertCanNotSeeTableRecords(array_map(fn($key) => $rows[$key]['tld'], array_diff(array_keys($rows), $keys)))
                ->assertCountTableRecords(count($keys));
        }
    } finally { DB::rollBack(); }
    echo "TLD selectors: same-registrar accounts, other registrar and combined selections passed.\n";
}
