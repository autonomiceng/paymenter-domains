<?php
// Adapted from Enom 444f31c DomainOperations/DomainManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Management\Changes;

use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementResult, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\ReadToken;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EntryDeadline, FreshTransaction, HoldingLocks,
    ManagementEvidence, ObservationProjection, OperationClaims, ReadDeferred, Runtime, StaffOperationReview, StaffReviewRefused};

trait ManagementReadback
{
    /** Explicit read-only reconciliation. Never calls prepare or perform, and cannot retry a write/email. */
    public function reconcile(int $id, ManagementProtocol $driver): Operation
    {
        return EntryDeadline::run($this->clock, fn ($deadline) => $this->reconcileEntered($id, $driver, $deadline), EntryDeadline::INTERACTIVE_SECONDS);
    }
    /** Legacy reads require a current, one-use staff review; earlier usage remains unknown. */
    public function reconcileReviewed(int $id, ManagementProtocol|\Closure $driver, StaffOperationReview $review): Operation
    {
        $op=Operation::findOrFail($id);
        if($review->operationId!==$id || $review->domainId!==$op->domain_id || $review->action!=='recheck'){
            throw new \DomainException('Management review does not match.');
        }
        return EntryDeadline::run($this->clock, fn($deadline)=>$review->read(
            function()use($id,$driver,$deadline){
                try{return $this->reconcileEntered($id,$driver instanceof \Closure ? $driver() : $driver,$deadline,true);}
                catch(StaffReviewRefused $error){throw $error;}
                catch(ReadDeferred $error){throw new StaffReviewRefused('Status checks for this account are busy. Try Check status again in 15 minutes. Automatic checks continue.');}
                catch(\Throwable $error){throw new StaffReviewRefused('The registrar did not return a usable status. Try Check status again in 15 minutes. Automatic checks continue.');}
            }),EntryDeadline::INTERACTIVE_SECONDS);
    }
    /** Shared state/send eligibility for a staff offer and the locked reader; this grants no read or retry. */
    public static function canReconcile(Operation $op, \DateTimeImmutable $now): bool
    {
        return !($op->state==='running'&&$op->lease_expires_at>$now)
            &&in_array($op->state,['uncertain','running'],true)&&($op->attempt['seal']??null)==='sealed';
    }
    private function reconcileEntered(int $id, ManagementProtocol $driver, \DateTimeImmutable $deadline, bool $reviewed=false): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $read = $this->locked($seed, $id, $driver, function ($l, $op) use ($driver, $reviewed, $deadline): ?array {
            if (!self::canReconcile($op, $this->clock->now())) { return null; }
            $b = AccountBindings::binding($l['account']);
            if (!$driver->bindsTo($b)) { throw new \DomainException('Read binding changed.'); }
            try { $this->bindings->requireReadableLocked($l['server'], $l['account']); }
            catch (\Throwable $error) {
                if ($reviewed) { throw new StaffReviewRefused('The registrar account cannot be read. Check its connection settings and try Check status again.'); }
                throw $error;
            }
            $token = $this->attempts->reserve($op, $deadline, $reviewed);
            if (!$token) { return null; }
            $op->reloadManagementAttempt();
            return [$this->descriptor($op, $l), HoldingLocks::take($l['domain'], (int) $op->authorized_by)['stamp'], $token];
        }, false, false);
        if ($read === null) {
            if ($reviewed) { throw new StaffReviewRefused('A status read cannot run for this request now. Reload the page and try again.'); }
            return Operation::findOrFail($id);
        }
        [$r, $stamp, $token] = $read;
        $ctx = new RequestContext($r->binding, RequestOrigin::Background, $deadline, new RequestBudget(10), $this->clock, Runtime::limiter());
        try { return $this->finish($seed, $id, $driver, $driver->observe($r, $ctx), $token, $stamp); }
        catch (\Throwable $error) {
            FreshTransaction::run(function () use ($id, $token): void {
                Operation::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->attempts->close($token);
            });
            if ($reviewed) { throw $error; }
            return Operation::findOrFail($id);
        }
    }
    /** Sealing changes the operation rows, so a write's readback compares only the saved record evidence it started from. */
    private static function evidence(Domain $d): string
    { return hash('sha256', json_encode($d->observation['records'] ?? null, JSON_THROW_ON_ERROR)); }
    private function finish(Domain $seed, int $id, ManagementProtocol $driver, ManagementResult $result, ReadToken $read, ?string $stamp = null, ?string $evidence = null): Operation
    {
        return $this->locked($seed, $id, $driver, function ($l, $op) use ($result, $stamp, $driver, $evidence, $read): Operation {
            if(!in_array($op->state,['running','uncertain'],true)||($op->attempt['seal']??null)!=='sealed'){throw new \DomainException('Management readback no longer applicable.');}
            if ($stamp !== null && $l['stamp'] !== $stamp) { throw new \DomainException('Management changed during readback.'); }
            // After an expired write lease, a scheduled read may save newer records before this readback finishes.
            $newer = $evidence !== null && self::evidence($l['domain']) !== $evidence;
            $facts = $result->facts(); $ack = $op->attempt['management_ack'] ?? [];
            $email=$op->action==='auth_code_email'&&($ack['email_accepted']??null)===true&&($result->confirmed||$result->emailAccepted); $confirmed=$result->confirmed&&$op->action!=='auth_code_email';
            $ok = ($confirmed || $email) && ($op->action!=='contact_profile'||isset($ack['profile_id'])) && (!in_array($op->action,['privacy_purchase','privacy_renew'],true) || isset($ack['order_id']));
            $projection=$l['domain']->observation??[]; $sequence=($projection['management_sequence']??0)+1;
            $entry = ['sequence'=>$sequence,'operation_key' => $op->operation_key, 'generation' => $op->authority_generation, 'action' => $op->action,
                'at' => $this->clock->now()->format(DATE_ATOM), 'correction_link' => $op->policy['management']['correction_link'] ?? null, 'confirmed' => $confirmed, 'email_accepted' => $email, 'facts' => $result->facts()];
            // A newer saved inventory stays the record source: this older confirmed read keeps its history but ranks no higher than it.
            if ($newer && in_array($op->action,['dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete'],true) && ($inventory=ObservationRecords::current($l['domain']))!==null) { $entry['sequence']=min($sequence,$inventory['sequence']); }
            $refused=$result->refused&&!$confirmed&&!$email;$terminal=$refused&&$op->funding_basis==='non_billable_management';
            $history = $op->attempt['management_reads'] ?? [];
            $recovery=$op->attempt['management_recovery']??null;
            if(!$recovery&&count($history)>=20){throw new \DomainException('Management read budget exhausted.');}
            $proof = $recovery ? [...$entry, 'recovery_number'=>$recovery['number']] : $entry;
            $this->attempts->finish($op, $read, $proof, $refused, $ok ? 'succeeded' : ($terminal ? 'rejected' : null));
            $op->update(['state' => $ok ? 'succeeded' : ($terminal?'rejected':'uncertain'),
                'safe_code' => $email ? 'auth_email_accepted' : ($ok ? 'readback_confirmed' : ($refused?'management_provider_refused':'management_unconfirmed')),
                'completed_at' => ($ok||$terminal) ? $this->clock->now() : null]);
            if ($ok && $confirmed && ManagementEvidence::resolved($op,$l['domain'])) {
                $section=match($op->action) {
                    'nameservers','lock'=>$op->action,
                    'privacy_purchase','privacy_renew','privacy_enable','privacy_disable'=>'privacy',
                    default=>null,
                };
                if ($section!==null) { $projection['sections'][$section]=ObservationProjection::section($l['domain'],$entry); }
                // Keep the latest per-host result (including deletion) after its operation leaves the history window.
                if (in_array($op->action,['glue_add','glue_update','glue_delete'],true) && is_string($facts['host']??null) && $facts['host']!=='') {
                    $projection['record_reads'][$facts['host']]=['identity'=>ObservationRecords::identity($l['domain']),...$entry];
                }
            }
            // A read ranked below the newer inventory is not a later read, so a long history does not treat that inventory as stale.
            $projection['management_sequence']=max($projection['management_sequence']??0,$entry['sequence']); $l['domain']->update(['observation'=>$projection]);
            if ($op->action==='dns' && $ok && $confirmed && !$newer && ManagementEvidence::resolved($op,$l['domain'])) {
                ObservationRecords::saveDnsReadback($l['domain'],$facts,$this->clock->now(),$driver->observationCapabilities());
            }
            return $op;
        }, false, false);
    }
}
