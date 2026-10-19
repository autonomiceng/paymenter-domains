<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery, HoldingLocks, InvoiceFunding};
$op = $new('identity-writer.com'); $domain = $op->domain; $service = $domain->service;
$other = commerceUser(); $orderId = $service->order_id;
$role = Role::create(['name' => 'Identity manager', 'permissions' => ['admin.domains.manage']]);
$staff = commerceUser(); $staff->update(['role_id' => $role->id]);
$management = Operation::create(['domain_id' => $op->domain_id, 'service_id' => $op->service_id, 'server_id' => $op->server_id,
    'registrar_key' => $op->registrar_key, 'account_fingerprint' => $op->account_fingerprint, 'authority_generation' => 1,
    'operation_key' => 'identity-contact-close', 'action' => 'contacts', 'term' => 1, 'currency' => 'USD', 'amount' => '0.00',
    'state' => 'held', 'safe_code' => 'contact_details_required', 'funding_basis' => 'non_billable_management']);
$raw = $management->fresh()->getAttributes();
$fund = fn () => DB::transaction(fn () => InvoiceFunding::lock($op->invoice_id)->requireInitial($op, $domain));
$holding = fn () => DB::transaction(fn () => HoldingLocks::take($domain));
$close = fn () => ContactRecovery::closeUnsent($staff, $management->id, 'Identity regression control');
$fund(); $holding();
same('rejected', $close()->state, 'Valid native identity allows contact closure');
DB::table('domains_operations')->where('id', $management->id)->update($raw);
DB::table('orders')->where('id', $orderId)->update(['user_id' => $other->id]);
foreach (['funding' => $fund, 'holding' => $holding, 'contact disposition' => $close] as $name => $call) {
    try { $call(); expect(false, 'Expected Order-owner refusal: '.$name); }
    catch (DomainException $error) { same('Holding native authority changed.', $error->getMessage(), 'Exact Order-owner refusal: '.$name); }
}
same('held', $management->fresh()->state, 'Drifted contact disposition made no mutation');
DB::table('orders')->where('id', $orderId)->update(['user_id' => $user->id]);
$fund(); $holding();

$connection = DB::connection(); $original = $connection->getQueryGrammar();
$trace = new class($connection) extends Illuminate\Database\Query\Grammars\SQLiteGrammar {
    public array $reads = [];
    public function compileSelect(Illuminate\Database\Query\Builder $query) {
        if ($query->lock) { $this->reads[] = ['table' => $query->from, 'lock' => $query->lock, 'orders' => $query->orders]; }
        return parent::compileSelect($query);
    }
};
foreach (['funding' => $fund, 'holding' => $holding, 'contact disposition' => $close] as $name => $call) {
    $trace->reads = []; $connection->setQueryGrammar($trace);
    try { $call(); } finally { $connection->setQueryGrammar($original); }
    $tables = array_column($trace->reads, 'table');
    expect(in_array('orders', $tables, true) && in_array('services', $tables, true), 'Current native locks: '.$name);
    expect(max(array_keys($tables, 'orders', true)) < min(array_keys($tables, 'services', true)), 'Every Order lock before every Service lock: '.$name);
    foreach (['orders', 'services'] as $table) {
        $read = $trace->reads[array_search($table, $tables, true)];
        same(true, $read['lock'], 'Current row lock: '.$name.' '.$table);
        same([['column' => 'id', 'direction' => 'asc']], $read['orders'], 'Sorted rows: '.$name.' '.$table);
    }
}
// Funding evidence also cannot survive a reset without Laravel's rollback event.
DB::beginTransaction(); $context = InvoiceFunding::lock($op->invoice_id); $pdo = $connection->getPdo(); DB::disconnect();
if ($pdo->inTransaction()) { $pdo->rollBack(); } $connection->setPdo($pdo);
DB::transaction(fn () => commerceReject(fn () => $context->requirePaid($op, $domain)));
DB::beginTransaction(); $context = InvoiceFunding::lock($op->invoice_id); $pdo->rollBack();
commerceReject(fn () => $context->requirePaid($op, $domain)); $connection->setPdo($pdo);
same(0, DB::transactionLevel(), 'No leaked native writer transaction');
$nativeSuccessful = true;
echo "Holding, funding and contact writers refuse Order drift, preserve lock order and expire reset contexts\n";
