<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Jobs\RunOperation;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt,AcquisitionLane,AcquisitionResult,AcquisitionRetryProof};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Registrars\Dispatch\Offers;
/** Durable poll backoff survives queue restarts; unique jobs bound worker backlog. */
final readonly class RuntimeDispatch
{
    public function __construct(private Clock $clock) {}
    public static function matchesHolding(Operation $op, ?Domain $domain, ?Account $account): bool
    {
        return $domain && !$domain->adoptionPending() && !($op->attempt['legacy_retained'] ?? false) && $account
            && $account->account_fingerprint === $op->account_fingerprint && $domain->account_fingerprint === $op->account_fingerprint
            && $domain->authority_generation === $op->authority_generation;
    }
    public function current(Operation $op, ?Account $account = null): bool
    {
        $d=Domain::find($op->domain_id);$a=$account ?? Account::where('server_id',$op->server_id)->first();
        if ($d && $op->action === 'renew' && in_array($op->state, ['awaiting_payment', 'queued'], true) && RenewalCycles::registrarVirgin($op) && RenewalStop::adopted(RenewalStop::read($d))) { ObligationAlerts::record($d, 'operation_review', $this->clock->now()->getTimestamp(), (int) $op->id); return false; }
        if (self::matchesHolding($op, $d, $a)) { return true; }
        if($d){ObligationAlerts::record($d,'operation_review',$this->clock->now()->getTimestamp(),(int)$op->id);}return false;
    }
    public function enqueue(int $id,bool $force=false): void
    {
        $op=Operation::findOrFail($id);
        if (AcquisitionAttempt::key($op->action) !== null) {
            $account = Account::where('server_id', $op->server_id)->first();
            $route = $account ? $this->acquisition($op, $account, false) : null;
            if ($route === null) { return; }
            [$op, $revision] = $route;
            if ($revision !== null) {
                // The job owns the revision's unique lock until the queue finishes handling it.
                DB::afterCommit(static fn () => RunOperation::dispatch($id, $revision));
                return;
            }
        } elseif (!$this->current($op)) { return; }
        $instant=$this->clock->now();
        $cutoffRecovery=ExecutionWindow::pastTransferCutoff($op,$instant)&&AcquisitionRetryProof::interruptedHold($op);
        if($op->state==='held'&&!$cutoffRecovery&&!ManagementEvidence::recoverable($op)&&!RenewalRetries::candidate($op)&&!TransferPolling::applies($op)
            &&(!(AcquisitionRetryProof::localRecovery($op,$instant)||ContactProfileHold::forReadmission($op,$instant))||!Account::where('server_id',$op->server_id)->value('processing_enabled'))){return;}
        $now=$instant->getTimestamp();
        if(!TransferPolling::due($op,$now)){return;}
        $due=DB::transaction(function()use($id,$op,$now,$force):bool {
            DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$id]);
            $q=DB::table('domains_runtime_operations')->where('operation_id',$id);$row=(clone $q)->lockForUpdate()->first();
            // Input may have committed while dispatch waited for the runtime row. Read without taking another lock.
            $op=$op->fresh();
            if(!TransferPolling::due($op,$now)){return false;}
            if(!$force&&!TransferPolling::applies($op)&&(int)$row->due_at>$now){return false;}
            $slowManagement=isset($op->policy['management'])&&in_array($op->state,['uncertain','running'],true)&&ManagementReadRecovery::exhausted($op->attempt);
            if($slowManagement){
                $ready=ManagementReadRecovery::last($op->attempt)?->getTimestamp();
                if($ready!==null&&$ready+21600>$now){$q->update(['due_at'=>$ready+21600]);return false;}
            }
            $poll=in_array($op->state,['pending','uncertain','running'],true)||TransferPolling::applies($op);$attempts=$poll?(int)$row->attempts+1:0;
            $waiting=$op->state==='held'||$op->state==='awaiting_payment'&&\App\Models\Invoice::whereKey($op->invoice_id)->value('status')!=='paid'||$op->state==='awaiting_code'&&!TransferInputs::validCode($op->payload['epp']??null);
            $delay=$slowManagement?21600:($poll?min(21600,300*(2**min(7,$attempts-1))):($waiting?900:60));
            if(TransferPolling::applies($op)){$delay=60;}
            $q->update(['attempts'=>$attempts,'due_at'=>$now+$delay]);return true;
        });
        if($due){RunOperation::dispatch($id)->afterCommit();}
    }

    /**
     * The scheduler and every delivered notification use the same current continuation and Orders gate.
     * The revision fences delivery to the continuation whose due time this decision checked.
     *
     * @return array{Operation, ?int}|null
     */
    public function acquisition(Operation $op, Account $account, bool $delivered, ?RuntimePorts $ports = null): ?array
    {
        $instant = $this->clock->now();
        $id = (int) $op->id;
        if (!$this->current($op, $account)) {
            if (BalanceRetries::pending($op)) { app(OperationClaims::class)->readmitBalance($id); }
            return null;
        }
        $recovering = AcquisitionRetryProof::expiredClaim($op, $instant)
            || AcquisitionRetryProof::interruptedHold($op) && !BalanceRetries::pending($op);
        if ($recovering && ExecutionWindow::pastTransferCutoff($op, $instant)) {
            app(OperationClaims::class)->holdUnclaimed($id, 'transfer_setup_review_required');
            return null;
        }
        if (!$account->processing_enabled) {
            $paidTest = $op->action === 'register' && $op->state === 'awaiting_payment'
                && \App\Models\Invoice::whereKey($op->invoice_id)->value('status') === 'paid'
                && self::testRegistrationEnabled($account);
            if ($recovering || $paidTest || $op->state === 'queued'
                && ($op->acquisitionAttemptFacts() || BalanceRetries::active($op))) {
                app(OperationClaims::class)->holdUnclaimed($id, 'processing');
                return null;
            }
            return self::statusWork($op) ? [$op, null] : null;
        }
        if ($recovering && !($ports ?? app(RuntimePorts::class))->accepted($account)) {
            app(OperationClaims::class)->holdUnclaimed($id, 'write_disabled');
            return null;
        }
        if ($op->state === 'held' && $op->safe_code === 'contact_details_required') {
            ContactRecovery::retryAccount($id, $this->clock);
            $op->refresh();
        }
        if ($op->state === 'held' && AcquisitionRetryProof::ordersOffHold($op)) {
            if (!app(OperationClaims::class)->readmitOrdersOff($id)) { return null; }
            $op->refresh();
        }
        if (self::statusWork($op) || $op->completed_at !== null) { return [$op, null]; }
        $continuation = AcquisitionResult::continuation($op);
        if (FundsWaits::deadlinePassed($continuation?->shortfallAt, $instant)) { return null; }
        $lane = $continuation?->lane;
        if (in_array($lane, [AcquisitionLane::Purchase, AcquisitionLane::BalancePurchase], true)
            && !$continuation->due($instant)) { return null; }
        if ($op->state === 'held') {
            if (in_array($lane, [AcquisitionLane::BalanceWait, AcquisitionLane::ContactWait, AcquisitionLane::PriceWait], true)) {
                if (!$continuation->due($instant)) { return null; }
                if ($lane === AcquisitionLane::BalanceWait && !$delivered) { return [$op, $continuation->revision]; }
                $readmitted = match ($lane) {
                    AcquisitionLane::BalanceWait => app(OperationClaims::class)->readmitBalance($id),
                    AcquisitionLane::ContactWait => ContactStepRetries::readmit($id, $this->clock),
                    AcquisitionLane::PriceWait => PriceHoldRetries::readmit($id, $this->clock),
                };
                if (!$readmitted || !($ready = $op->fresh()) || $ready->state !== 'queued') { return null; }
                $admitted = AcquisitionResult::continuation($ready);
                if (!in_array($admitted?->lane, [AcquisitionLane::Purchase, AcquisitionLane::BalancePurchase], true)
                    || !$admitted->due($this->clock->now())) { return null; }
                return [$ready, $admitted->revision];
            }
            if (!AcquisitionRetryProof::localRecovery($op, $instant)
                && !ContactProfileHold::forReadmission($op, $instant) && !TransferPolling::applies($op)) { return null; }
        }
        if ($lane === AcquisitionLane::FundsWait) { return null; }
        return [$op, $lane === null ? null : $continuation->revision];
    }

    private static function statusWork(Operation $op): bool
    {
        return in_array($op->state, ['pending','uncertain','running'], true) || TransferPolling::applies($op);
    }

    private static function testRegistrationEnabled(Account $account): bool
    {
        try {
            $binding = AccountBindings::binding($account);
            return $binding->registrarKey === 'enom' && Offers::salesFeature($binding, 'register', setup: true);
        }
        catch (\Throwable) { return false; }
    }
}
