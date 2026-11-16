<?php
// Refund credit decisions under database contention: one fresh retry, then durable deferred work. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';

use App\Models\{Credit, Gateway, Invoice, InvoiceTransaction, Role};
use Illuminate\Database\{DeadlockException, QueryException};
use Illuminate\Support\Facades\{Cache, DB, Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement, MoneyDecisions, MoneyOutcome, MoneyRequest, MoneyWorkSchema, MoneyWorkUnqueued, NativeIntentSchema, PricingRule, Refunds, StaffOperationReview, StaffReviewRefused};

class ContentionCreditGateway extends \App\Classes\Extension\Gateway
{
    public static bool $held = false;
    public function pay(Invoice $invoice, $total) { return ''; }
    public function receiptLedger(Invoice $invoice): array
    {
        if (!self::$held) { return []; }
        $row = InvoiceTransaction::where('invoice_id', $invoice->id)->sole();
        return [['invoice_id'=>$invoice->id, 'user_id'=>$invoice->user_id, 'currency'=>'USD', 'payment_intent_id'=>$row->transaction_id,
            'allocation_key'=>'stripe:dispute:synthetic_hold', 'state'=>'open', 'amount_minor'=>PricingRule::minor($row->amount)]];
    }
}
class_alias(ContentionCreditGateway::class, 'Paymenter\\Extensions\\Gateways\\ContentionCredit\\ContentionCredit');
$gateway = Gateway::create(['extension'=>'ContentionCredit', 'name'=>'Offline credit', 'type'=>'gateway', 'enabled'=>true]);
$staff = commerceUser();
$staff->update(['role_id'=>Role::create(['name'=>'Refund retry', 'permissions'=>['admin.domains.view','admin.domains.manage','admin.invoices.update',
    StaffOperationReview::permission('credit')]])->id]);
$refunds = new Refunds($clock); app()->instance(Refunds::class, $refunds);
NativeIntentSchema::install(); $runner = is_file(dirname(__DIR__).'/Domains/Services/MoneyDecisions.php'); // The base revision reaches its first defect instead of a missing class.
if ($runner) { MoneyWorkSchema::install(); }
$retirement = new AcquisitionRetirement($clock); app()->instance(AcquisitionRetirement::class, $retirement);
$account = Account::where('server_id', $tlds[0]->server_id)->sole(); $cursor = 'domains.closed-credit-cursor.'.$account->server_id;
$balance = static fn(): int => PricingRule::minor((string)(Credit::where('user_id',$user->id)->value('amount') ?? '0.00'));
$work = static fn(): array => !$runner ? [] : DB::table(MoneyWorkSchema::TABLE)->orderBy('id')->get(['kind','subject_id','revision'])->map(fn($r)=>(array)$r)->all();
$repaired = static fn(Operation $op): bool => DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists();
$busy = static function(string $state, int $driver, bool $wrapped = false): Throwable {
    $cause = new PDOException('Synthetic database error', $state === '40001' ? 40001 : 0); $cause->errorInfo = [$state, $driver, 'Synthetic'];
    $error = new QueryException('sqlite', 'synthetic credit write', [], $cause);
    return $wrapped ? new DeadlockException($error->getMessage(), 0, $error) : $error;
};
// Fail the first $fails credit writes with $error; each attempt must own the only open transaction.
$attempts = 0; $inject = null;
Credit::saved(static function() use (&$inject): void { $inject?->__invoke(); });
$failing = static function(int $fails, Throwable $error) use (&$attempts, &$inject): void {
    $attempts = 0;
    $inject = static function() use (&$attempts, $fails, $error): void {
        $attempts++; same(1, DB::transactionLevel(), 'The credit write runs in the one outer transaction');
        if ($attempts <= $fails) { throw $error; }
    };
};
$rejected = static function(string $name) use ($new, $gateway, $refunds, $staff): Operation {
    $op = $new($name); $op->update(['state'=>'held','safe_code'=>'staff_review']);
    DB::table('invoice_transactions')->where('invoice_id',$op->invoice_id)->update(['gateway_id'=>$gateway->id, 'transaction_id'=>'synthetic_'.$op->id]);
    // An open dispute keeps the credit waiting after the staff decision commits.
    ContentionCreditGateway::$held = true; $refunds->refundHeld($staff, $op->id, 'customer_cancelled'); ContentionCreditGateway::$held = false;
    return $op->fresh();
};
$closed = static function(string $name) use ($new, $gateway, $retirement): Operation {
    $op = $new($name, false);
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'cancelled']);
    DB::table('services')->where('id',$op->service_id)->update(['status'=>'cancelled']);
    $retirement->retire($op->domain_id);
    InvoiceTransaction::create(['invoice_id'=>$op->invoice_id, 'amount'=>'3.00', 'fee'=>'0.00', 'status'=>'succeeded', 'gateway_id'=>$gateway->id, 'transaction_id'=>'synthetic_closed_'.$op->id]);
    return $op->fresh();
};

// One deadlock: the whole transaction rolls back and the fresh retry credits exactly once, with no repair or queued work.
$op = $rejected('contention-once-placeholder.com'); $before = $balance();
$failing(1, $busy('HY000', 1213));
$refunds->retryPending($op->id - 1, 1); $inject = null;
same(2, $attempts, 'A deadlock is retried once');
same($before + PricingRule::minor($op->amount), $balance(), 'The retry credits exactly once');
same(false, $repaired($op), 'Contention is not a repair'); same([], $work(), 'A retried success queues nothing');

// Two lock wait timeouts: nothing commits and the decision is queued. The next sweep finishes it.
$op = $rejected('contention-twice-placeholder.com'); $before = $balance(); $money = $nativeMoney(); $funding = $op->fresh()->funding;
$failing(2, $busy('HY000', 1205));
same(0, $refunds->retryPending($op->id - 1, 1), 'A deferred refund reports nothing credited'); $inject = null;
same([$money, $funding], [$nativeMoney(), $op->fresh()->funding], 'A deferred refund commits no money or funding');
same(false, $repaired($op), 'Exhausted contention is not a repair');
same([['kind'=>'refund_rejected','subject_id'=>$op->id,'revision'=>1]], $work(), 'Exhausted contention queues the decision');
// Deferred again during the sweep: the newer revision stays queued.
$failing(2, $busy('40001', 0, true));
$refunds->creditRetired($account); $inject = null;
same([['kind'=>'refund_rejected','subject_id'=>$op->id,'revision'=>2]], $work(), 'A repeat deferral keeps its newer revision queued');
// A full retry budget of newer deferrals cannot push it out of the next sweep.
$newer = array_map(fn(int $i) => MoneyDecisions::run(new MoneyRequest($op->domain, 'refund_rejected', 900000 + $i), fn() => throw $busy('HY000', 1213))->outcome, range(1, 25));
same(array_fill(0, 25, MoneyOutcome::Deferred), $newer, 'Newer contention defers 25 decisions');
$refunds->creditRetired($account);
same($before + PricingRule::minor($op->amount), $balance(), 'The next sweep credits the older deferral exactly once');
same([[900025], false], [array_column($work(), 'subject_id'), $repaired($op)], 'Completed work leaves the queue with no repair; the newest waits its turn');
DB::table(MoneyWorkSchema::TABLE)->delete();

// A staff retry that meets contention says to try again and leaves nothing queued.
$op = $rejected('contention-staff-placeholder.com'); $before = $balance();
$review = new StaffOperationReview((int)$staff->id, $op->domain_id, $op->id, 'credit',
    StaffOperationReview::ticket((int)$staff->id, $op->domain_id, $op->id, 'credit', StaffOperationReview::stamp($op->fresh(), $op->domain->fresh()), $clock), 'Reviewed current evidence', $clock);
$failing(2, $busy('HY000', 1213));
try { $refunds->creditRejected($op->id, $review); throw new LogicException('Expected a try-again refusal'); }
catch (StaffReviewRefused $e) { same(MoneyDecisions::BUSY, $e->getMessage(), 'Staff are told to try again'); }
$inject = null;
same([$before, [], false], [$balance(), $work(), $repaired($op)], 'A busy staff retry changes nothing');

// A real failure records the repair, today's staff path.
$failing(1, $busy('23000', 1062));
$refunds->retryPending($op->id - 1, 1); $inject = null;
same([1, true, []], [$attempts, $repaired($op), $work()], 'A permanent error is not retried and records a repair');

// The closed-order sweep cannot queue a deferral: it stops before that order and keeps its cursor.
$op = $closed('contention-closed-placeholder.com'); $before = $balance(); Cache::forever($cursor, $op->id - 1);
Schema::rename(MoneyWorkSchema::TABLE, 'domains_money_work_away');
$failing(2, $busy('HY000', 1213));
try { $refunds->creditRetired($account); throw new LogicException('Expected an unqueued deferral'); } catch (MoneyWorkUnqueued) {}
$inject = null; Schema::rename('domains_money_work_away', MoneyWorkSchema::TABLE);
same([$op->id - 1, $before, false], [(int)Cache::get($cursor), $balance(), $repaired($op)], 'An unqueued deferral keeps the cursor and records no repair');
// Queued, the cursor moves on and the queue finishes the order.
$failing(2, $busy('HY000', 1213));
$refunds->creditRetired($account); $inject = null;
same([['kind'=>'refund_closed','subject_id'=>$op->id,'revision'=>1]], $work(), 'The closed order is queued before the cursor passes it');
same([$before, false], [$balance(), $repaired($op)], 'A queued closed order has no credit or repair yet');
same([$op->domain_id], $refunds->creditRetired($account), 'The queued closed order is credited and reported');
same([$before + 300, []], [$balance(), $work()], 'Closed order credit is exact and its work is done');

// The runner opens the only transaction.
$ran = false; $nested = static function() use (&$ran) { $ran = true; };
try { DB::transaction(fn() => MoneyDecisions::run(new MoneyRequest($op->domain, released: true), $nested)); throw new RuntimeException('Expected nesting refusal'); }
catch (LogicException) {}
same(false, $ran, 'A runner inside a transaction is refused before its decision runs');
$inner = MoneyDecisions::run(new MoneyRequest($op->domain, released: true), fn() => MoneyDecisions::run(new MoneyRequest($op->domain, released: true), $nested));
same([MoneyOutcome::Failed, LogicException::class, false], [$inner->outcome, $inner->error ? $inner->error::class : null, $ran], 'A runner inside a runner is refused before its decision runs');
same(0, DB::transactionLevel(), 'No transaction is left open');
echo "Refund contention retries once, then queues the decision for the next sweep.\n";
$nativeSuccessful = true;
