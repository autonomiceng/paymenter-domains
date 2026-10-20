<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation, SalesPolicy};

// Bootstrap-free: checkout and adopted-holding fixtures can share these assertions.
function scenarioSwitch($sellers, $clock, ?int $server): void
{
    $policy = SalesPolicy::where('tld', 'com')->sole();
    $sellers->change('com', $policy->revision, $server, $server !== null, $server !== null, $clock->now());
}
function scenarioMoney(): array
{
    $rows = [];
    foreach (['invoices', 'invoice_items', 'invoice_transactions', 'credits'] as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    }
    return $rows;
}
function scenarioAuthority(Domain $domain): array
{
    $domain = $domain->fresh();
    $fields = [
        'id', 'service_id', 'user_id', 'server_id', 'registrar_key', 'account_fingerprint',
        'domain', 'reservation_name', 'authority_generation', 'provenance', 'adoption_id',
        'provider_domain_id', 'provider_expires_at', 'confirmed_revision', 'snapshot', 'renewal_policy',
    ];
    foreach ($fields as $field) { expect(array_key_exists($field, $domain->getRawOriginal()), 'Authority field exists: '.$field); }
    return array_intersect_key($domain->getRawOriginal(), array_flip($fields));
}
function scenarioRetained(Domain $domain, callable $action): void
{
    $authority = scenarioAuthority($domain); $money = scenarioMoney();
    $operations = $domain->operations()->orderBy('id')->get()->map(static fn ($op) => $op->getRawOriginal())->all();
    $action();
    same($authority, scenarioAuthority($domain), 'Seller changes retain exact holding authority and encrypted provenance');
    same($operations, $domain->operations()->orderBy('id')->get()->map(static fn ($op) => $op->getRawOriginal())->all(), 'Seller changes retain every original operation byte');
    same($money, scenarioMoney(), 'Seller changes create no debt, payment or credit');
}
function scenarioFund(Operation $op): Operation
{
    App\Helpers\ExtensionHelper::addPayment($op->invoice_id, null, $op->amount);
    same(1, App\Models\Invoice::findOrFail($op->invoice_id)->transactions()->count(), 'One native payment');
    return $op->fresh();
}
function scenarioExecution(Operation $op, bool $lost = false): void
{
    $attempt = $op->fresh()->attempt;
    expect(array_key_exists('execution', $attempt), 'Sealed attempt records its execution outcome');
    if ($lost) { same(null, $attempt['execution'], 'Intentional response loss records null execution'); return; }
    same('sent', $attempt['execution']['send_state'] ?? null, 'Nominal fixture response reached the real protocol');
    if ($op->action === 'transfer') { same('pending', $attempt['execution']['disposition'] ?? null, 'Transfer acknowledgement parsed outside engine exception handling'); }
    else { same('accepted', $attempt['execution']['disposition'] ?? null, 'Registration acknowledgement and readback parsed'); }
}
