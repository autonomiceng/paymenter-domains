<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Order, Service};
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingIdentityLock, HolderPricing, NativeDomainGuard};
Queue::fake(); extract(commerceFixture()); $owner = commerceUser(); $other = commerceUser();
$product = $native->product($tlds[0], 'register');
$order = Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
$second = Order::create(['user_id' => $owner->id, 'currency_code' => 'USD']);
$service = Service::create(['user_id' => $owner->id, 'order_id' => $order->id, 'product_id' => $product->id,
    'plan_id' => $product->plans()->where('billing_period', 1)->sole()->id, 'quantity' => 1,
    'price' => '10.00', 'currency_code' => 'USD', 'status' => 'pending']);
$service->properties()->create(['key' => 'domain', 'value' => 'identity-lock.com']);
same(0, Domain::count(), 'Lock API must work before a ledger row');
$lock = fn () => HoldingIdentityLock::lockForAdoption($order->id, $service->id);
commerceReject($lock);
$expected = ['order_id' => $order->id, 'service_id' => $service->id,
    'order_user_id' => $owner->id, 'service_user_id' => $owner->id];
$context = DB::transaction(function () use ($lock, $owner, $expected) {
    $context = $lock(); same($expected, $context->owners($owner->id), 'Rechecked owners and order relation');
    commerceReject(fn () => $context->owners($owner->id + 1000));
    commerceReject(fn () => clone $context); commerceReject(fn () => serialize($context)); return $context;
});
DB::transaction(fn () => commerceReject(fn () => $context->owners($owner->id)));
DB::beginTransaction(); $rolled = $lock(); DB::rollBack();
DB::transaction(fn () => commerceReject(fn () => $rolled->owners($owner->id)));
// Disconnect resets Laravel's level without a rollback event. Keep the disposable SQLite PDO to retain the fixture.
DB::beginTransaction(); $disconnected = $lock(); $pdo = DB::connection()->getPdo(); DB::disconnect();
if ($pdo->inTransaction()) { $pdo->rollBack(); }
DB::connection()->setPdo($pdo);
DB::transaction(fn () => commerceReject(fn () => $disconnected->owners($owner->id)));
DB::beginTransaction(); $lost = $lock(); $pdo->rollBack();
commerceReject(fn () => $lost->owners($owner->id)); DB::connection()->setPdo($pdo);
DB::beginTransaction(); $savepoint = $lock(); DB::beginTransaction(); DB::rollBack();
commerceReject(fn () => $savepoint->owners($owner->id)); DB::rollBack();
DB::transaction(fn () => commerceReject(fn () => HoldingIdentityLock::lockForAdoption($second->id, $service->id)));
DB::transaction(fn () => commerceReject(fn () => HoldingIdentityLock::lockForAdoption($order->id, PHP_INT_MAX)));

// SQLite omits FOR UPDATE from SQL. Inspect the public builder grammar input to prove requested locks/order.
$connection = DB::connection(); $original = $connection->getQueryGrammar();
$trace = new class($connection) extends Illuminate\Database\Query\Grammars\SQLiteGrammar {
    public array $reads = [];
    public function compileSelect(Illuminate\Database\Query\Builder $query) {
        if ($query->lock) { $this->reads[] = ['table' => $query->from, 'lock' => $query->lock, 'orders' => $query->orders]; }
        return parent::compileSelect($query);
    }
};
$connection->setQueryGrammar($trace);
try { DB::transaction($lock); } finally { $connection->setQueryGrammar($original); }
$tables = array_column($trace->reads, 'table');
foreach (['users', 'invoices', 'invoice_items', 'invoice_transactions', 'orders', 'services', 'products', 'plans'] as $table) {
    expect(in_array($table, $tables, true), 'Missing current locking read: '.$table);
}
foreach ([['users', 'invoices'], ['invoices', 'orders'], ['invoice_items', 'orders'],
    ['invoice_transactions', 'orders'], ['orders', 'services'], ['services', 'products'], ['products', 'plans']] as [$a, $b]) {
    expect(max(array_keys($tables, $a, true)) < min(array_keys($tables, $b, true)), 'Native lock order: '.$a.' before '.$b);
}
foreach (['orders', 'services'] as $table) {
    $read = $trace->reads[array_search($table, $tables, true)];
    same(true, $read['lock'], 'Current row lock: '.$table);
    same([['column' => 'id', 'direction' => 'asc']], $read['orders'], 'Sorted native rows: '.$table);
}

// Controlled competitors commit first. API-only safety requires current equality AND the preview owner.
// The adoption-first ordering requires a separate native refusal protocol and is deliberately not claimed here.
$phase = null; $armed = null;
foreach (['before-guard' => 'saving', 'after-guard-before-sql' => 'updating'] as $point => $event) {
    if ($event === 'updating') {
        (new NativeDomainGuard(new HolderPricing($prices), new \Paymenter\Extensions\Servers\Domains\Data\SystemClock))->boot();
    }
    $listener = static function ($row) use (&$phase, &$armed, $point): void {
        if ($armed === get_class($row) && $phase === $point) { Fiber::suspend($point); }
    };
    Order::$event($listener); Service::$event($listener);
}
foreach (['before-guard', 'after-guard-before-sql'] as $point) {
    foreach (['order-owner', 'service-owner', 'service-order', 'both-owners'] as $edit) {
        $phase = $point; $armed = str_starts_with($edit, 'service') ? Service::class : Order::class;
        $competitor = new Fiber(function () use ($edit, $order, $service, $second, $other): void {
            DB::transaction(function () use ($edit, $order, $service, $second, $other): void {
                if (in_array($edit, ['order-owner', 'both-owners'], true)) { $order->fresh()->update(['user_id' => $other->id]); }
                if (in_array($edit, ['service-owner', 'both-owners'], true)) { $service->fresh()->update(['user_id' => $other->id]); }
                if ($edit === 'service-order') { $service->fresh()->update(['order_id' => $second->id]); }
            });
        });
        same($point, $competitor->start(), 'Competitor paused at '.$point.' for '.$edit);
        $armed = null; $competitor->resume(); expect($competitor->isTerminated(), 'Competing edit committed first');
        DB::transaction(fn () => commerceReject(fn () => $lock()->owners($owner->id)));
        $order->fresh()->update(['user_id' => $owner->id]);
        $service->fresh()->update(['user_id' => $owner->id, 'order_id' => $order->id]);
        DB::transaction(fn () => same($expected, $lock()->owners($owner->id), 'Valid identity recovers after fixture restoration'));
    }
}
same(0, DB::transactionLevel(), 'No leaked identity transaction');
same(0, Domain::count(), 'Adoption remains unimplemented and off');
$nativeSuccessful = true;
echo "Native order-before-service locks, atomic owner/relation recheck and paused commit-first competitors passed\n";
