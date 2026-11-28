<?php
// Enom444f31c shared refresh semantics; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget, RequestContext, RequestOrigin, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Money\Native\InvoiceFunding;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionLifecycle, AcquisitionReadPermit, OperationWriter, ReadToken};
use Paymenter\Extensions\Servers\Domains\Holding\Observation\{ObservationRecords, ObservationEligibility};
/** Dormant read-only refresh. The financial reconciliation/completion services are never called. */
final class DomainObservation
{
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock, private readonly ReadLimiter $budget, private readonly ?OwnerPorts $records=null, private readonly ?\Closure $recordGate=null, private readonly ?\Closure $observationReader=null) {}
    /** A core-only observer cannot fill record inventory; do not repeatedly wake it for missing families. */
    public function readsRecords(): bool { return $this->records!==null||$this->observationReader!==null; }
    public function run(int $id, ?int $generation=null, ?string $fingerprint=null, bool $readRecords=true, ?\Closure $current=null, ?\Closure $completion=null, ?\Closure $attempted=null): string
    {
        OperationClaims::outside(); if($current){$current();} $seed=Domain::findOrFail($id);
        if (($generation!==null&&$seed->authority_generation!==$generation)||($fingerprint!==null&&$seed->account_fingerprint!==$fingerprint)) { throw new ReadDeferred('Stale observation work.'); }
        $capture = FreshTransaction::run(function () use ($seed, $readRecords, $current): ?array {
            $l = HoldingLocks::take($seed, beforeCatalogue: $current);
            $at = $this->clock->now();
            $attempt = $read = null;
            if (in_array($l['domain']->status, ['registration_pending', 'transfer_pending'], true) && !ObservationEligibility::independent($l['domain'])) {
                $action = $l['domain']->status === 'transfer_pending' ? 'transfer' : 'register';
                $op = $l['operations']->first(fn ($row) => $row->action === $action
                    && $row->authority_generation === $l['domain']->authority_generation
                    && in_array($row->state, ['running', 'pending', 'uncertain', 'held'], true));
                if (!$op) { return null; }
                $state = AcquisitionLifecycle::settle($op, $l['domain'], $at);
                if (!$state->permitsRead() || $state->readBusy($at) || $current && TransferPolling::applies($op)) {
                    return null;
                }
            }
            $this->available($l);
            $this->bindings->requireReadableLocked($l['server'], $l['account']);
            if (ObservationRecords::identity($seed) !== ObservationRecords::identity($l['domain'])) { throw new ReadDeferred('Stale observation work.'); }
            $binding = AccountBindings::binding($l['account']);
            if (isset($state)) {
                try {
                    InvoiceFunding::requireInitialFacts($op, $l);
                    $attempt = ReconciliationEvidence::attempt($op, $l['domain'], $binding);
                } catch (\DomainException) { return null; }
                $read = OperationWriter::at($at)->openRead($state->facts->token, $at->modify('+250 seconds'));
                if (!$read instanceof ReadToken) { return null; }
            }
            $recordProtected = !$read && ($this->records !== null || $this->observationReader !== null) && (!$this->recordGate || ($this->recordGate)($l));
            $recordEnabled = $readRecords && $recordProtected;
            $knownGlue = $recordEnabled ? (ObservationProjection::sections($l['domain'])['known_glue'] ?? []) : [];
            $recordOverflow = count($knownGlue) > GlueDiscoveryLimit::HOSTS;
            $stamp = $read ? ($l['stampForHeldRows'])() : $l['stamp'];
            return [$binding, $stamp, $attempt, $l['domain'], $knownGlue, $recordOverflow, $recordEnabled, $recordProtected, $read];
        });
        if (!$capture) { throw new ReadDeferred('Acquisition status read unavailable.'); }
        [$binding, $stamp, $attempt, $seed, $knownGlue, $recordOverflow, $recordEnabled, $recordProtected, $read] = $capture;
        $credential=$current?$this->bindings->holdingCredentialRevision($binding):null;
        // Report only the configuration this attempt verified, never a later repaired configuration.
        if($attempted){$attempted($credential);}
        $wireGuard=$current?function()use($current,$binding,$credential):void {
            $current();
            if(!hash_equals($credential,$this->bindings->holdingCredentialRevision($binding))){throw new ReadDeferred('Observation credentials changed.');}
        }:null;
        $ctx=new RequestContext($binding,RequestOrigin::Background,$read?->until ?? $this->clock->now()->modify('+250 seconds'),new RequestBudget(25),$this->clock,$this->budget,stopOnReadFailure:true,readNameservers:true,current:$wireGuard,
            observationName:$recordEnabled && $seed->status==='active' && !$recordOverflow ? $seed->domain : null);
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
        } catch (ReadDeferred|EnomTestCallLimit $e) {
            if (!$read) { throw $e; }
            $snapshot = null; $transfer = TransferStatus::Unknown; $deferred = true;
        }
        catch (\Throwable $e) {
            $failureCode=ReadFailure::code($e);
            if (!$read && (!in_array($seed->status,['active','grace','expired'],true)||(!$recordProtected&&$failureCode!=='domain_not_in_account'))) { throw $e; }
            $snapshot=null; $transfer=TransferStatus::Unknown; $failed=true;
        }
        // A failed primary read retains inventory; record failures retain successful domain facts.
        if (!$failed && $recordEnabled && $seed->status==='active' && !$recordOverflow && !$recordHeld) {
            $records=['glue'=>['state'=>'partial','records'=>$knownGlue]];
            if ($snapshot?->owned===true) {
                try {
                    if($current){$current();}
                    // A resolver may return null: that runtime has no dedicated reader, so the legacy read and row format stay.
                    $port=$this->observationReader?($this->observationReader)($binding):null;
                    if ($port!==null && (!$port instanceof \Paymenter\Extensions\Servers\Domains\Contracts\ObservationReader || !$port->bindsTo($binding))) { throw new \DomainException('Observation reader binding changed.'); }
                    $capabilities=$port?->observationCapabilities();
                    $port??=$this->records?->forDomain($seed);
                    if (!$port instanceof \Paymenter\Extensions\Servers\Domains\Contracts\ObservationReader || !$port->bindsTo($binding)) { throw new \DomainException('Observation reader binding changed.'); }
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
        $code=FreshTransaction::run(function () use ($seed,$binding,$stamp,$snapshot,$transfer,$records,$failed,$recordFailed,$deferred,$recordOverflow,$recordHeld,$recordEnabled,$readRecords,$failureCode,$current,$credential,$capabilities,$failedFamily,$read): ?string {
            $l=HoldingLocks::take($seed,beforeCatalogue:$current);
            if ($read) {
                $op = $l['operations']->firstWhere('id', $read->attempt->operationId);
                $now = $this->clock->now();
                $permit = $op ? AcquisitionReadPermit::issue(AcquisitionLifecycle::settle($op, $l['domain'], $now), $read, $now) : null;
                if (!$permit) { return null; }
                $writer = OperationWriter::at($now);
                if ($stamp !== $l['stamp']) {
                    $writer->closePurchaseRead($permit);
                    return null;
                }
                try {
                    $this->available($l);
                    $this->bindings->requireReadableLocked($l['server'], $l['account']);
                    $binding->requireMatch(AccountBindings::binding($l['account']));
                    InvoiceFunding::requireInitialFacts($op, $l);
                    if ($current && !hash_equals($credential, $this->bindings->holdingCredentialRevision($binding))) { throw new ReadDeferred('Observation credentials changed.'); }
                } catch (\DomainException) {
                    $writer->closePurchaseRead($permit);
                    return null;
                }
                if (!$failed && !$deferred && ($snapshot || $transfer !== TransferStatus::Unknown)) {
                    ObservationProjection::save($l['domain'], $snapshot, $transfer, $now, $read->attempt->attemptId);
                }
                if (!$writer->closePurchaseRead($permit)) { throw new ReadDeferred('Observation read changed.'); }
                return $deferred ? 'read_deferred' : ($failureCode ?? ($transfer === TransferStatus::Pending ? 'healthy_pending' : 'unresolved'));
            }
            $this->available($l);
            $this->bindings->requireReadableLocked($l['server'],$l['account']);
            $binding->requireMatch(AccountBindings::binding($l['account']));
            if($current&&!hash_equals($credential,$this->bindings->holdingCredentialRevision($binding))){throw new ReadDeferred('Observation credentials changed.');}
            if ($stamp!==$l['stamp']) { return null; }
            if ($recordEnabled && $this->recordGate && !($this->recordGate)($l)) { throw new ReadDeferred('Record processing authority changed.'); }
            RenewalOwnership::retainAbsence($l['domain'],$this->clock->now(),$failed&&$failureCode==='domain_not_in_account');
            if ($records!==null && count($records['glue']['records']??[])>GlueDiscoveryLimit::HOSTS) { $records=null; $recordOverflow=true; }
            if ($records!==null) { ObservationRecords::save($l['domain'],$records,$this->clock->now(),$capabilities); }
            if ($snapshot || $transfer!==TransferStatus::Unknown) {
                RenewalOwnership::observe($l['domain'],$snapshot,$this->clock->now());
                ObservationProjection::save($l['domain'],$snapshot,$transfer,$this->clock->now());
            }
            $incomplete=$records!==null && ObservationRecords::incomplete($l['domain'],$this->clock->now());
            if ($incomplete && $failedFamily===null) {
                $inventory=ObservationRecords::current($l['domain'],$this->clock->now());
                foreach (['dns','srv','ds','glue'] as $kind) {
                    if (($inventory['expected_families'][$kind]??'supported')==='supported' && ($inventory['families'][$kind]['state']??null)!=='complete'
                        && !($binding->registrarKey==='enom' && $kind==='glue' && ($inventory['families'][$kind]['state']??null)==='partial' && !($inventory['families'][$kind]['read_failed']??false))) { $failedFamily=$kind;break; }
                }
                foreach ($inventory['settings']??[] as $kind=>$family) { if ($failedFamily===null && $family['state']!=='complete') { $failedFamily=$kind; } }
            }
            if ($recordEnabled && !$recordHeld && ($recordOverflow || $recordFailed || ($records['glue']['read_failed']??false) || $incomplete)) {
                ObservationRecords::hold($l['domain'],$recordOverflow?'child_host_limit':($snapshot?->owned===false?'ownership_drift':(($records['glue']['read_failed']??false)&&$failedFamily==='glue'?'glue_unavailable':(($records['dns']['caa']??false)&&$failedFamily==='dns'?'caa_unsupported':'records_unavailable'))),$this->clock->now(),$failureCode,$failedFamily);
            }
            if ($failed) { ObservationRecords::failPrimary($l['domain'],$this->clock->now(),$failureCode); return $failureCode; }
            if ($snapshot?->owned===false && in_array($l['domain']->status,['active','grace','expired'],true)) { ObservationRecords::failPrimary($l['domain'],$this->clock->now(),'domain_not_in_account'); return 'domain_not_in_account'; }
            if ($deferred) { return 'read_deferred'; }
            $baseCode=$l['domain']->status==='transfer_pending'&&$transfer===TransferStatus::Pending?'healthy_pending':(in_array($l['domain']->status,['active','grace','expired'],true)&&!($l['domain']->observation['drift']??true)?'refreshed':'unresolved');
            // A record gate must never hide drift, pending status or another unresolved core result.
            if ($baseCode==='refreshed' && $readRecords && ($this->records!==null || $this->observationReader!==null) && !$recordEnabled) { return 'records_disabled'; }
            return $baseCode;
        });
        if ($code === null) { throw new ReadDeferred('Observation authority changed.'); }
        if (!$read) {
            FreshTransaction::run(function()use($seed,$code):void { $d=HoldingLocks::take($seed)['domain'];
                if(ObservationRecords::identity($d)===ObservationRecords::identity($seed)){DepartureChecks::capture($d,$this->clock->now()->getTimestamp(),$code==='domain_not_in_account');} });
        }
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
