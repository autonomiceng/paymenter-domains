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
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock, private readonly ReadLimiter $budget, private readonly ?OwnerPorts $records=null, private readonly ?\Closure $recordGate=null, private readonly ?\Closure $observationReader=null) {}
    public function run(int $id, ?int $generation=null, ?string $fingerprint=null, bool $readRecords=true, ?\Closure $current=null, ?\Closure $completion=null): string
    {
        OperationClaims::outside(); if($current){$current();} $seed=Domain::findOrFail($id);
        if (($generation!==null&&$seed->authority_generation!==$generation)||($fingerprint!==null&&$seed->account_fingerprint!==$fingerprint)) { throw new ReadDeferred('Stale observation work.'); }
        [$binding,$stamp,$attempt,$seed,$knownGlue,$recordOverflow,$recordEnabled,$recordProtected]=DB::transaction(function () use ($seed,$readRecords,$current): array {
            $l=HoldingLocks::take($seed,beforeCatalogue:$current); $this->available($l); $this->bindings->requireReadableLocked($l['server'],$l['account']);
            if (ObservationRecords::identity($seed)!==ObservationRecords::identity($l['domain'])) { throw new ReadDeferred('Stale observation work.'); }
            $binding=AccountBindings::binding($l['account']); $attempt=null;
            if ($l['domain']->status==='transfer_pending') {
                $op=$l['operations']->first(fn($o)=>$o->action==='transfer'&&$o->authority_generation===$l['domain']->authority_generation&&in_array($o->state,['pending','uncertain','held'],true)&&($o->attempt['seal']??null)==='sealed');
                if ($op) { $attempt=ReconciliationEvidence::attempt($op,$l['domain'],$binding); }
            }
            $recordProtected=($this->records!==null || $this->observationReader!==null) && (!$this->recordGate || ($this->recordGate)($l));
            $recordEnabled=$readRecords && $recordProtected;
            $knownGlue=$recordEnabled ? (ObservationProjection::sections($l['domain'])['known_glue']??[]) : [];
            $recordOverflow=count($knownGlue)>GlueDiscoveryLimit::HOSTS;
            return [$binding,$l['stamp'],$attempt,$l['domain'],$knownGlue,$recordOverflow,$recordEnabled,$recordProtected];
        });
        $credential=$current?$this->bindings->holdingCredentialRevision($binding):null;
        $wireGuard=$current?function()use($current,$binding,$credential):void {
            $current();
            if(!hash_equals($credential,$this->bindings->holdingCredentialRevision($binding))){throw new ReadDeferred('Observation credentials changed.');}
        }:null;
        $ctx=new RequestContext($binding,RequestOrigin::Background,$this->clock->now()->modify('+250 seconds'),new RequestBudget(25),$this->clock,$this->budget,stopOnReadFailure:true,readNameservers:true,current:$wireGuard);
        $snapshot=null; $transfer=TransferStatus::Unknown; $records=null; $failed=false; $recordFailed=false; $deferred=false; $failureCode=null; $capabilities=null; $failedFamily=null;
        $recordHeld=ObservationRecords::held($seed) && !$readRecords;
        try {
            if($current){$current();}
            $reader=$this->bindings->reader($binding->serverId);
            if ($attempt) {
                $result=$reader->reconcile($attempt,$ctx); $result->requireAttempt($attempt); $facts=$result->result()->evidence();
                $snapshot=$facts->domain; $transfer=$facts->transfer;
            } else { $result=$reader->readDomain($seed->domain,$ctx); $binding->requireMatch($result->binding); $snapshot=$result->evidence()->domain; }
            if ($snapshot) { $binding->requireMatch($snapshot->binding); if ($snapshot->name!==$seed->domain) { throw new \DomainException('Observation name changed.'); } }
            if ($this->clock->now()>=$ctx->deadline) { throw new \DomainException('Observation deadline reached.'); }
            if (!$snapshot&&$transfer===TransferStatus::Unknown) { throw new \DomainException('Observation unavailable.'); }
        } catch (ReadDeferred|EnomTestCallLimit $e) { throw $e; }
        catch (\Throwable $e) {
            if (!$recordProtected || $seed->status!=='active') { throw $e; }
            $snapshot=null; $transfer=TransferStatus::Unknown; $failed=true; $failureCode=ReadFailure::code($e);
        }
        // A failed primary read retains inventory; record failures retain successful domain facts.
        if (!$failed && $recordEnabled && $seed->status==='active' && !$recordOverflow && !$recordHeld) {
            $records=['glue'=>['state'=>'partial','records'=>$knownGlue]];
            if ($snapshot?->owned===true) {
                try {
                    if($current){$current();}
                    $port=$this->observationReader?($this->observationReader)($binding):$this->records->forDomain($seed);
                    if (!$port instanceof \Paymenter\Extensions\Servers\Domains\Contracts\ObservationReader || !$port->bindsTo($binding)) { throw new \DomainException('Observation reader binding changed.'); }
                    $capabilities=$this->observationReader?$port->observationCapabilities():null;
                    if ($binding->registrarKey==='enom') { $knownGlue=GlueRecords::known($seed,$knownGlue,$snapshot?->nameservers?->nameservers??[]); }
                    $r=new \Paymenter\Extensions\Servers\Domains\Data\ManagementRequest($binding,'observation:'.$seed->id,$seed->domain,'discovery',[],$seed->provider_domain_id,domainId:(int)$seed->id);
                    $records=$port->discover($r,$ctx,$knownGlue);
                    $failedFamily=$records['failed_family']??null;
                    if (isset($records['failure_code'])) { $failureCode=ReadFailure::safe($records['failure_code']); }
                    if ($this->clock->now()>=$ctx->deadline) { throw new \DomainException('Observation deadline reached.'); }
                } catch (ReadDeferred|EnomTestCallLimit) { $records=null; $deferred=true; }
                catch (GlueDiscoveryLimit) { $records=null; $recordOverflow=true; }
                catch (\Throwable $error) { $records=['glue'=>['state'=>'partial','records'=>$knownGlue]]; $recordFailed=true; $failureCode=ReadFailure::code($error); }
            }
        }
        $code=DB::transaction(function () use ($seed,$binding,$stamp,$snapshot,$transfer,$records,$failed,$recordFailed,$deferred,$recordOverflow,$recordHeld,$recordEnabled,$readRecords,$failureCode,$current,$credential,$capabilities,$failedFamily): string {
            $l=HoldingLocks::take($seed,beforeCatalogue:$current); $this->available($l); $this->bindings->requireReadableLocked($l['server'],$l['account']);
            $binding->requireMatch(AccountBindings::binding($l['account']));
            if($current&&!hash_equals($credential,$this->bindings->holdingCredentialRevision($binding))){throw new ReadDeferred('Observation credentials changed.');}
            if ($stamp!==$l['stamp']) { throw new ReadDeferred('Observation authority changed.'); }
            if ($recordEnabled && $this->recordGate && !($this->recordGate)($l)) { throw new ReadDeferred('Record processing authority changed.'); }
            if ($records!==null && count($records['glue']['records']??[])>GlueDiscoveryLimit::HOSTS) { $records=null; $recordOverflow=true; }
            if ($records!==null) { ObservationRecords::save($l['domain'],$records,$this->clock->now(),$capabilities); }
            if ($snapshot || $transfer!==TransferStatus::Unknown) { ObservationProjection::save($l['domain'],$snapshot,$transfer,$this->clock->now()); }
            $incomplete=$records!==null && ObservationRecords::incomplete($l['domain']);
            if ($incomplete && $failedFamily===null) {
                $inventory=ObservationRecords::current($l['domain']);
                foreach (['dns','srv','ds','glue'] as $kind) {
                    if (($inventory['expected_families'][$kind]??'supported')==='supported' && ($inventory['families'][$kind]['state']??null)!=='complete'
                        && !($binding->registrarKey==='enom' && $kind==='glue' && ($inventory['families'][$kind]['state']??null)==='partial' && !($inventory['families'][$kind]['read_failed']??false))) { $failedFamily=$kind;break; }
                }
                foreach ($inventory['settings']??[] as $kind=>$family) { if ($failedFamily===null && $family['state']!=='complete') { $failedFamily=$kind; } }
            }
            if ($recordEnabled && !$recordHeld && ($recordOverflow || $recordFailed || ($records['glue']['read_failed']??false) || $incomplete)) {
                ObservationRecords::hold($l['domain'],$recordOverflow?'child_host_limit':($snapshot?->owned===false?'ownership_drift':(($records['glue']['read_failed']??false)&&!$incomplete?'glue_unavailable':'records_unavailable')),$this->clock->now(),$failureCode,$failedFamily);
            }
            if ($failed) { ObservationRecords::failPrimary($l['domain'],$this->clock->now(),$failureCode); return $failureCode; }
            if ($deferred) { return 'read_deferred'; }
            if ($readRecords && ($this->records!==null || $this->observationReader!==null) && !$recordEnabled) { return 'records_disabled'; }
            return $l['domain']->status==='transfer_pending'&&$transfer===TransferStatus::Pending?'healthy_pending':($l['domain']->status==='active'&&!($l['domain']->observation['drift']??true)?'refreshed':'unresolved');
        });
        return $completion ? $completion($code,$wireGuard) : $code;
    }
    /** Observation must yield before it can invalidate a live management/financial grant. */
    private function available(array $locked): void
    {
        if (!ObservationEligibility::allows($locked['domain'],$locked['operations'])) { throw new ReadDeferred('Acquisition not submitted.'); }
        foreach ($locked['operations'] as $op) {
            if (($op->state==='running'&&$op->lease_expires_at!==null&&$op->lease_expires_at->toDateTimeImmutable()>$this->clock->now())
                || ($op->state==='queued'&&isset($op->attempt['management_claim']))) { throw new ReadDeferred('Holding authority busy.'); }
        }
    }
}
