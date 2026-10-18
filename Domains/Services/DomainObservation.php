<?php
// Enom444f31c shared refresh semantics; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Dormant read-only refresh. The financial reconciliation/completion services are never called. */
final class DomainObservation
{
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock, private readonly ReadLimiter $budget) {}
    public function run(int $id, ?int $generation=null, ?string $fingerprint=null): string
    {
        OperationClaims::outside(); $seed=Domain::findOrFail($id);
        if (($generation!==null&&$seed->authority_generation!==$generation)||($fingerprint!==null&&$seed->account_fingerprint!==$fingerprint)) { throw new ReadDeferred('Stale observation work.'); }
        [$binding,$stamp,$attempt]=DB::transaction(function () use ($seed): array {
            $l=HoldingLocks::take($seed); $this->available($l); $this->bindings->requireReadableLocked($l['server'],$l['account']);
            if ($seed->authority_generation!==$l['domain']->authority_generation) { throw new ReadDeferred('Stale observation work.'); }
            $binding=CatalogueAcceptances::binding($l['account']); $attempt=null;
            if ($l['domain']->status==='transfer_pending') {
                $op=$l['operations']->first(fn($o)=>$o->action==='transfer'&&$o->authority_generation===$l['domain']->authority_generation&&in_array($o->state,['pending','uncertain','held'],true)&&($o->attempt['seal']??null)==='sealed');
                if ($op) { $attempt=ReconciliationEvidence::attempt($op,$l['domain'],$binding); }
            }
            return [$binding,$l['stamp'],$attempt];
        });
        $ctx=new RequestContext($binding,RequestOrigin::Background,$this->clock->now()->modify('+250 seconds'),new RequestBudget(25),$this->clock,$this->budget,stopOnReadFailure:true);
        $reader=$this->bindings->reader($binding->serverId); $transfer=TransferStatus::Unknown;
        if ($attempt) {
            $result=$reader->reconcile($attempt,$ctx); $result->requireAttempt($attempt); $facts=$result->result()->evidence();
            $snapshot=$facts->domain; $transfer=$facts->transfer;
        } else { $result=$reader->readDomain($seed->domain,$ctx); $binding->requireMatch($result->binding); $snapshot=$result->evidence()->domain; }
        if ($snapshot) { $binding->requireMatch($snapshot->binding); if ($snapshot->name!==$seed->domain) { throw new \DomainException('Observation name changed.'); } }
        if ($this->clock->now()>=$ctx->deadline || (!$snapshot&&$transfer===TransferStatus::Unknown)) { throw new \DomainException('Observation unavailable.'); }
        return DB::transaction(function () use ($seed,$binding,$stamp,$snapshot,$transfer): string {
            $l=HoldingLocks::take($seed); $this->available($l); $this->bindings->requireReadableLocked($l['server'],$l['account']);
            $binding->requireMatch(CatalogueAcceptances::binding($l['account']));
            if ($stamp!==$l['stamp']) { throw new ReadDeferred('Observation authority changed.'); }
            ObservationProjection::save($l['domain'],$snapshot,$transfer,$this->clock->now());
            return $l['domain']->status==='transfer_pending'&&$transfer===TransferStatus::Pending?'healthy_pending':($l['domain']->status==='active'&&!($l['domain']->observation['drift']??true)?'refreshed':'unresolved');
        });
    }
    /** Observation must yield before it can invalidate a live management/financial grant. */
    private function available(array $locked): void
    {
        foreach ($locked['operations'] as $op) {
            if (($op->state==='running'&&$op->lease_expires_at!==null&&$op->lease_expires_at->toDateTimeImmutable()>$this->clock->now())
                || ($op->state==='queued'&&isset($op->attempt['management_claim']))) { throw new ReadDeferred('Holding authority busy.'); }
        }
    }
}
