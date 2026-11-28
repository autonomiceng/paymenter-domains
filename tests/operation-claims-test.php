<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-claims.php';
require_once __DIR__.'/Support/fixture-cases.php';
require_once __DIR__.'/Support/acquisition-evidence-fixture.php';
use App\Models\Credit;
use Illuminate\Support\Facades\{Cache, Crypt, DB};
use Paymenter\Extensions\Servers\Domains\Data\{Disposition, Money};
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAdmission, AcquisitionResult, OperationAttempts, OperationSchema, StepKey};
use Paymenter\Extensions\Servers\Domains\Services\{FundsWaits, HoldingLocks, MoneyWorkSchema, PricingRule, RefundProof, Refunds, WriteAcceptance, WriteHeld};
// Claim, seal, funds cutoff and send outcome cases share the one prepared claim fixture and clock. Each makes
// its own paid native checkout; the reset restores rows, payments, attempts, listeners, cache and fakes after it.
$cases['claims'] = static function () use ($new, $engine, $writer, $assertNoSend): void {
    $assertNoSend($new('unpaid.example.com', false)); $assertNoSend($new('setup.example.com', true, false));
    $op = $new('ordinary.example.com'); $result = $engine->execute($op->id);
    same('uncertain', $result->state, 'Unknown response holds obligation'); same('ordinary.example.com', $result->domain->reservation_name, 'Unknown holds name');
    same('99.00', $writer->last->authorizedCost->amount, 'Known cost above paid retail is allowed: no dispatch margin floor');
    same('possibly_sent', $result->attempt['execution']['send_state'], 'Normalized execution retained');
    $assertNoSend($result, 'uncertain'); commerceReject(fn () => $engine->consume($writer->last, $writer->context, new WriteAcceptance($writer->last->binding,catalogue: $GLOBALS['caps']??null)));
    $forged = clone $writer->last; commerceReject(fn () => $engine->consume($forged, $writer->context, new WriteAcceptance($forged->binding,catalogue: $GLOBALS['caps']??null)));
    foreach (['execution', 'dispatch_attempted_at', 'claim_key', 'seal'] as $key) {
        $attempt = $result->attempt; unset($attempt[$key]); commerceReject(fn () => $result->fresh()->update(['attempt' => $attempt]));
    }
};
$cases['attempt-authority'] = static function () use ($new, $engine, $writer): void {
    foreach (['register', 'transfer'] as $action) {
        $op = $new('ledger-'.$action.'.example.com', action: $action);
        $stale = $op->fresh();
        same([], $stale->attempt ?? [], 'The earlier instance has no attempt yet');
        $before = $writer->calls;
        $result = $engine->execute($op->id)->fresh();
        $payload = $result->payload;
        commerceReject(fn () => $stale->update(['payload' => [...$payload, 'nameservers' => ['ns1.example.net', 'ns2.example.net']]]));
        same($payload, $result->fresh()->payload, 'A stale instance cannot change the sealed payload');
        same($before + 1, $writer->calls, 'Exactly one acquisition crossed the writer boundary');
        $attempt = OperationAttempts::sealed((int) $result->id, new StepKey($action, 1));
        expect($attempt !== null && $attempt->grantConsumedAt !== null, 'Acquisition seal and grant are durable across reload');
        same('unknown', OperationAttempts::evidence((int) $result->id, $attempt->token->attemptId, 'reply')['outcome'] ?? null,
            'The active attempt owns the reply');
        same([null, null, null], [$result->getRawOriginal('attempt'), $result->getRawOriginal('request_fingerprint'),
            $result->getRawOriginal('lease_expires_at')], 'The operation has no mirrored claim or reply');
        $engine->execute($result->id);
        same($before + 1, $writer->calls, 'A restarted worker cannot send again');
    }
};
$cases['possibly-sent-capacity-retry'] = static function () use ($new, $claims, $clock): void {
    $op = $new('capacity-prior-send.example.com');
    [$claimed] = $claims->claim($op->id);
    $attempt = [...$claimed->attempt, 'send_state' => 'possibly_sent',
        'capacity_deferrals' => [$clock->now()->format(DATE_ATOM)]];
    restoreAcquisitionFixture([...$claimed->getRawOriginal(), 'state' => 'queued', 'safe_code' => 'write_rate_limited',
        'attempt' => Crypt::encryptString(json_encode($attempt, JSON_THROW_ON_ERROR)),
        'funding' => Crypt::encryptString(json_encode($claimed->funding, JSON_THROW_ON_ERROR)),
        'started_at' => OperationAttempts::at($claimed->started_at), 'request_fingerprint' => $claimed->request_fingerprint]);

    $retry = $claims->claim($op->id);
    $current = $op->fresh();
    same([false, 'uncertain', 'prior_attempt', 'possibly_sent'],
        [$retry !== null, $current->state, $current->safe_code, $current->attempt['send_state']],
        'A legacy capacity deferral preserves possible send evidence and gives no worker another claim');
};
$cases['capacity-deferral-limit'] = static function () use ($new, $claims, $clock): void {
    $op = $new('deferral-limit.example.com');
    [$worker] = $claims->claim($op->id);
    $op = $claims->finish($worker, holdReason: 'staff_review_required');
    for ($i = 0; $i < 7; $i++) {
        if ($op->state === 'held') {
            $claims->locked($op->id, static function (Operation $held) use ($clock): void {
                AcquisitionResult::admit($held, AcquisitionAdmission::StaffRelease, AcquisitionResult::revision($held),
                    $held->acquisitionAttemptFacts()->token, $clock,
                    static fn () => $held->update(['state' => 'queued', 'safe_code' => null]))->requireApplied();
            });
        }
        [$worker] = $claims->claim($op->id);
        $op = $claims->defer($worker);
    }
    same(['held', null, 6, true], [$op->state, $op->lease_expires_at, count($op->attempt['capacity_deferrals']),
        OperationAttempts::releasedUnsent($op->acquisitionAttemptFacts())], 'Capacity refusal retires the worker without exceeding six deferrals');
};
$cases['stale-claim-evidence'] = static function () use ($new, $engine, $preflight, $writer): void {
    $op = $new('stale-claim.example.com');
    $op->update(['state' => 'queued']);
    $staleAttempt = $op->fresh();
    $staleFunding = Operation::withAcquisitionStep()->findOrFail($op->id);
    same('queued', $staleAttempt->state, 'The stale model predates the claim');
    same([], $staleAttempt->attempt ?? [], 'The stale attempt projection is empty');
    same([], $staleFunding->attempt ?? [], 'The stale funding writer has no attempt');
    $originalReader = clone $staleAttempt;

    $refused = [];
    $readsCurrentClaim = false;
    $preflight->during = static function (Operation $claimed) use ($staleAttempt, $staleFunding, $originalReader, &$refused, &$readsCurrentClaim): void {
        $claimKey = $claimed->attempt['claim_key'];
        $readsCurrentClaim = ($originalReader->getOriginal('attempt')['claim_key'] ?? null) === $claimKey;
        foreach ([
            [$staleAttempt, 'attempt', ['claim_key' => str_repeat('a', 32)]],
            [$staleFunding, 'funding', ['claim' => []]],
        ] as [$stale, $field, $value]) {
            try {
                $stale->update([$field => $value]);
                $refused[$field] = false;
            } catch (\DomainException) {
                $refused[$field] = true;
            }
        }
    };
    $before = $writer->calls;
    $result = $engine->execute($op->id);
    $preflight->during = null;

    $seal = OperationAttempts::sealed((int) $result->id, new StepKey('register', 1));
    $expected = ['original_reads_claim' => true, 'attempt_refused' => true, 'funding_refused' => true,
        'sends' => 1, 'sealed_and_consumed' => true];
    $actual = ['original_reads_claim' => $readsCurrentClaim, 'attempt_refused' => $refused['attempt'] ?? false,
        'funding_refused' => $refused['funding'] ?? false, 'sends' => $writer->calls - $before,
        'sealed_and_consumed' => $seal?->grantConsumedAt !== null];
    same($expected, $actual, 'Stale claim regression: '.json_encode($actual, JSON_THROW_ON_ERROR));
};
$cases['inherited-refund-snapshot'] = static function () use ($new, $engine): void {
    $op = $new('refund-snapshot.example.com');
    $holding = $op->domain;
    $sent = $engine->execute($op->id);
    same('uncertain', $sent->state, 'The worker sent without a confirmed reply');
    // SQLite has no current locking reads. Hide the new ledger rows only from consistent reads.
    $connection = DB::connection();
    $original = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new class($connection) extends Illuminate\Database\Query\Grammars\SQLiteGrammar {
        public function compileSelect(Illuminate\Database\Query\Builder $query)
        {
            if (!$query->lock && in_array($query->from, [OperationSchema::STEPS.' as s', OperationSchema::EVIDENCE], true)) {
                $query = (clone $query)->whereRaw('0 = 1');
            }
            return parent::compileSelect($query);
        }
    });
    try {
        DB::transaction(static function () use ($holding, $op): void {
            $locks = HoldingLocks::take($holding);
            $locked = $locks['operations']->firstWhere('id', $op->id);
            commerceReject(fn () => RefundProof::requireUnsent($locked, $locks['domain']));
            same('possibly_sent', $locked->attempt['execution']['send_state'], 'The holding retains the current attempt under core locks');
        });
    } finally { $connection->setQueryGrammar($original); }
};
$cases['funds-cutoff'] = static function () use ($new, $engine, $preflight, $clock, $user, $assertNoSend): void {
    $op = $new('funds-seal-cutoff.example.com');
    $preflight->during = static fn () => throw new WriteHeld('registrar_balance_insufficient', new Money('99.00', 'USD'));
    $result = $assertNoSend($op); $preflight->during = null;
    same('registrar_balance_insufficient', $result->safe_code, 'A confirmed shortfall records a sweepable unsent hold');
    same(null, $result->lease_expires_at, 'No running lease remains after the shortfall');
    $clock->instant = $clock->now()->modify('+3 days +1 second');
    $engine->execute($op->id);same('held',$op->fresh()->state,'Later worker cannot turn this no-send into stale_claim');
    $credit=fn()=>PricingRule::minor((string)(Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));$before=$credit();
    app()->instance(Refunds::class,new Refunds($clock));
    $account=Account::where('server_id',$op->server_id)->sole();
    // Two deadlocks roll the whole closure back and queue it with no staff review; the next sweep runs it first.
    $runner=is_file(dirname(__DIR__).'/Domains/Services/MoneyWorkSchema.php');if($runner){MoneyWorkSchema::install();}
    $fails=2;Operation::updating(static function(Operation $row)use(&$fails):void {
        if($fails<1||$row->state!=='rejected'||!$row->isDirty('state')){return;}
        $fails--;same(1,DB::transactionLevel(),'The funds closure runs in one outer transaction');
        $cause=new PDOException('Synthetic deadlock');$cause->errorInfo=['40001',1213,'Synthetic'];
        throw new Illuminate\Database\QueryException('sqlite','synthetic funds closure',[],$cause);
    });
    FundsWaits::sweep($account,$clock->now());
    same([0,'held',0],[$fails,$op->fresh()->state,DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','funds_wait_review')->count()],'Contention twice changes nothing and asks no staff review');
    same([['kind'=>'funds_wait','subject_id'=>$op->id]],!$runner?[]:DB::table('domains_money_work')->get(['kind','subject_id'])->map(fn($r)=>(array)$r)->all(),'The deferred closure is queued');
    Cache::forever('domains.funds-wait-cursor.'.$op->server_id,$op->id); // Only the queue can reach it.
    FundsWaits::sweep($account,$clock->now());
    same(['rejected',0],[$op->fresh()->state,DB::table('domains_money_work')->count()],'The next sweep closes the queued claim and finishes the work');
    FundsWaits::sweep($account,$clock->now());
    same($before+PricingRule::minor($op->amount),$credit(),'Elapsed claim returns its payment once without staff repair');
};
$cases['send-outcomes'] = static function () use ($new, $engine, $preflight, $writer, $assertNoSend): void {
    $op = $new('preseal.example.com'); $preflight->fail = true; $result = $assertNoSend($op); $preflight->fail = false;
    same('absent', $result->attempt['seal'], 'Failure before seal retains absent marker'); expect(!array_key_exists('execution', $result->attempt), 'Preseal execution stays absent');
    $op = $new('postseal.example.com'); $writer->fail = true; $result = $engine->execute($op->id); $writer->fail = false;
    same('uncertain', $result->state, 'Lost response is uncertain'); expect(array_key_exists('execution', $result->attempt) && $result->attempt['execution'] === null, 'Postseal recorded-null differs from absent');
    $assertNoSend($result, 'uncertain');
    $op = $new('ack.example.com'); $writer->disposition = Disposition::Accepted; same('uncertain', $engine->execute($op->id)->state, 'Ack is not business completion'); $writer->disposition = Disposition::Unknown;
};
nativeFixtureCases($cases, compact('clock', 'preflight', 'writer', 'engine', 'claims', 'bindings', 'user'));
$nativeSuccessful=true;echo "Native operation claim scenario passed.\n";
