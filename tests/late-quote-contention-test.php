<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/late-renewal.php';

use App\Models\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, MoneyWorkSchema};

MoneyWorkSchema::install();
$target = null; $attempts = 0; $failures = 0; $inject = false; $error = null;
Domain::retrieved(static function (Domain $domain) use (&$target, &$attempts, &$failures, &$inject, &$error): void {
    if (!$inject || $domain->id !== $target || DB::transactionLevel() === 0) { return; }
    same(1, DB::transactionLevel(), 'Late quote capture owns the outer transaction');
    $attempts++;
    if ($attempts <= $failures) { throw $error; }
    $inject = false;
});
$busy = static function (int $driver): QueryException {
    $cause = new PDOException('Synthetic late quote lock error'); $cause->errorInfo = ['HY000', $driver, 'Synthetic'];
    return new QueryException('sqlite', 'synthetic late quote capture', [], $cause);
};
$failing = static function (Domain $domain, int $count, Throwable $cause) use (&$target, &$attempts, &$failures, &$inject, &$error): void {
    $target = $domain->id; $attempts = 0; $failures = $count; $error = $cause; $inject = true;
};
$work = static fn(): array => DB::table(MoneyWorkSchema::TABLE)->orderBy('id')->get(['kind', 'subject_id', 'revision'])->map(fn($r) => (array)$r)->all();
$debts = static fn(): array => [Invoice::count(), Operation::count()];

// A failed capture is retried before the first registrar read, then creates one late invoice.
[$domain] = $holder('contention-once'); $toggle(true);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'], ['value'=>true, 'type'=>'boolean']);
Cache::flush(); $state->calls = [];
$failing($domain, 1, $busy(1213));
$state->during = static function () use (&$attempts, &$inject): void {
    same(2, $attempts, 'The late quote retries once before reading the registrar'); $inject = false;
};
$invoice = $lateBilling->invoice($domain->id); $inject = false;
expect($invoice instanceof Invoice, 'One capture deadlock still issues the late renewal invoice');
same(2, $attempts, 'The successful capture uses its one fresh retry');
same([], $work(), 'Successful capture leaves no deferred work');
same(1, Operation::where('domain_id', $domain->id)->where('invoice_id', $invoice->id)->get()->filter(fn($op) => isset($op->policy['renewal']['late']))->count(), 'The late quote creates exactly one frozen debt');

// Exhausted capture contention must stop before ordinary issuance and optional registrar reads.
[$domain] = $holder('contention-twice'); Cache::flush(); $state->calls = []; $before = $debts();
$failing($domain, 2, $busy(1205));
same(null, $lateBilling->invoice($domain->id), 'Exhausted scheduled capture defers without an invoice'); $inject = false;
same([2, $before, []], [$attempts, $debts(), $state->calls], 'Two failed captures leave debt unchanged and perform no registrar read');
same([['kind'=>DomainBilling::KIND, 'subject_id'=>$domain->id, 'revision'=>1]], $work(), 'Exhausted capture queues one renewal issue for the domain');
$account = Account::where('server_id', $domain->server_id)->sole();
$failing($domain, 2, $busy(1213));
$lateBilling->sweep($account); $inject = false;
same([2, $before, []], [$attempts, $debts(), $state->calls], 'A deferred sweep also stops after two captures');
same([['kind'=>DomainBilling::KIND, 'subject_id'=>$domain->id, 'revision'=>2]], $work(), 'Repeat deferral preserves one queued issue with a new revision');
$lateBilling->sweep($account);
same([], $work(), 'A later successful sweep acknowledges the deferred issue');
same([$before[0] + 1, $before[1] + 1], $debts(), 'The deferred issue eventually creates exactly one invoice and operation');
expect(count($state->calls) > 0, 'The successful sweep fetches fresh registrar evidence outside the transaction');

// Manual requests preserve their immediate database error and never queue an automatic renewal.
[$domain] = $holder('contention-manual'); Cache::flush(); $state->calls = []; $before = $debts();
$failing($domain, 2, $busy(1205));
try { $lateBilling->invoice($domain->id, $user, 1); throw new LogicException('Expected manual capture failure'); }
catch (QueryException $caught) { same($error, $caught, 'Manual capture keeps its original error'); }
$inject = false;
same([1, $before, [], []], [$attempts, $debts(), $state->calls, $work()], 'Manual contention is not retried or queued');

// Permanent database errors on the automatic path also retain their existing failure behavior.
$cause = new PDOException('Synthetic permanent database error'); $cause->errorInfo = ['23000', 1062, 'Synthetic'];
$failing($domain, 2, new QueryException('sqlite', 'synthetic permanent capture error', [], $cause));
try { $lateBilling->invoice($domain->id); throw new LogicException('Expected permanent capture failure'); }
catch (QueryException $caught) { same($error, $caught, 'A permanent capture error is propagated'); }
$inject = false;
same([1, $before, [], []], [$attempts, $debts(), $state->calls, $work()], 'Permanent errors are not retried or queued');
same(0, DB::transactionLevel(), 'No capture transaction remains open');
echo "Late quote capture retries once, defers one renewal issue, and keeps registrar reads outside transactions.\n";
$nativeSuccessful = true;
