<?php
// Adapted from Enom 444f31c DomainOperations/DomainManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Management\Changes;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Management\Records\GlueRecords;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ManagementProtocol};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, RequestBudget, RequestContext, RequestOrigin, Support};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Money\Returns\RefundPayment;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AttemptToken, OperationAttempts, ReadToken};
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountSpendingHolds, DepartureChecks, EnomTestCalls,
    EnomTestCallLimit, EnomTestManagement, EnomTestWriteReservation, EntryDeadline, FreshTransaction, HoldingLocks,
    ManagementEvidence, OperationClaims, ReadDeferred, RenewalAuthority, RenewalStop, Runtime, WriteFeatures, WriteHeld};
/** Dormant engine boundary. All reads/writes outside locks; every request owns one durable attempt. */
final class DomainManagement
{
    use ManagementRequests, ManagementReadback;

    /** available() refused because the account's own switch for these changes is Off. */
    public const SWITCH_OFF = 'switch';
    private readonly ManagementAuthority $attempts;
    private ?ManagementGrant $grant = null;
    private ?ManagementGrant $receiptGrant = null;
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock,
        private readonly WriteFeatures $features = new WriteFeatures, private readonly bool $accepted = false,
        private readonly int $intervalSeconds = 25, private readonly bool $testManagement = false, private readonly ?\Closure $localCheck = null) { $this->attempts = new ManagementAuthority($clock); }
    public static function capability(string $action): string
    {
        return match ($action) {
            'privacy_enable', 'privacy_disable', 'privacy_purchase', 'privacy_renew' => 'privacy',
            'contact_profile' => 'contacts',
            'ds_add', 'ds_delete' => 'ds', 'glue_add', 'glue_update', 'glue_delete' => 'glue', 'srv' => 'dns',
            'nameservers', 'contacts', 'lock', 'dns', 'auto_renew_off', 'auth_code_email' => $action,
            default => throw new \DomainException('Unsupported management action.'),
        };
    }
    public function run(int $id, ManagementProtocol $driver, RequestOrigin $origin = RequestOrigin::Interactive, int $interactiveSeconds = EntryDeadline::INTERACTIVE_SECONDS): Operation
    {
        if (!ManagementSequence::prepare($id, $this, $driver, $origin, $this->clock->now(), $interactiveSeconds)) { return Operation::findOrFail($id); }
        $work=fn()=>$driver->withinReservation(Operation::findOrFail($id),fn()=>EntryDeadline::run($this->clock, fn ($deadline) => $this->runEntered($id, $driver, $origin, $deadline),
            $origin === RequestOrigin::Background ? EntryDeadline::BACKGROUND_SECONDS : max(1,min(EntryDeadline::INTERACTIVE_SECONDS,$interactiveSeconds))));
        try {
            if(!$this->testManagement){return $work();}
            $op=Operation::findOrFail($id);$b=AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->firstOrFail());
            return $b->registrarKey==='enom'&&EnomTestManagement::enabled($b)?EnomTestWriteReservation::run($b,'management',$id,$work):$work();
        }catch(WriteHeld $e){if($e->reason!=='write_acceptance_required'){throw $e;}return Operation::findOrFail($id);}
    }
    private function runEntered(int $id, ManagementProtocol $driver, RequestOrigin $origin, \DateTimeImmutable $deadline): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $op = Operation::findOrFail($id);
        if (ManagementEvidence::recoverable($op)) { $op = $this->resumePreparation($seed, $id); }
        if(!isset($op->policy['management'])){throw new \DomainException('Not a management operation.');}
        if ($op->state === 'running' && (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now())) {
            return $this->expireRunning($seed, $id);
        }
        if (!in_array($op->state, ['queued', 'awaiting_payment'], true) || (($op->attempt ?? []) !== []&&!ManagementEvidence::retryable($op))) { return $op; }
        if ($op->funding_basis === 'paid_invoice' && \App\Models\Invoice::find($op->invoice_id)?->status !== 'paid') { return $op; }
        $refusals = EnomTestCalls::refusalGeneration();
        $token = $read = null;
        try {
            [$r, $stamp, $token] = $this->locked($seed, $id, $driver, function ($l, $op): array {
                $this->features->requireManagement();
                if (!$this->accepted || $this->intervalSeconds < 25 || $this->intervalSeconds > 86400) { throw new \DomainException('Management acceptance required.'); }
                $this->peers($l, $op->id, $op->policy['management']['correction'] ?? null, $op->action);
                if (!ManagementEvidence::retryable($op) && ($op->payload??[]) !== ($op->policy['management']['input']??null)) { throw new \DomainException('Management desired state changed.'); }
                if ((($op->attempt ?? []) !== []&&!ManagementEvidence::retryable($op)) || !in_array($op->state, ['queued', 'awaiting_payment'], true)) { throw new \DomainException('Management attempt already exists.'); }
                if ($op->funding_basis === 'paid_invoice') { RefundPayment::requireCurrent($op, $l); $this->noInflight($op); }
                if(Cache::has('domains-write-slot:'.$op->account_fingerprint)){throw new WriteHeld('write_rate_limited');}
                $now = $this->clock->now(); $until = $now->modify('+360 seconds');
                $token = $this->attempts->claim($op, $until);
                $op->update(['payload'=>$op->policy['management']['input'], 'state'=>'running']);
                return [$this->descriptor($op, $l), HoldingLocks::take($l['domain'], (int)$op->authorized_by)['stamp'], $token];
            });
            $ctx = new RequestContext($r->binding, $origin, $deadline, new RequestBudget(10), $this->clock, Runtime::limiter());
            $driver->preparedRecords();
            $prepared = $driver->prepare($r, $ctx);
            $records = $driver->preparedRecords();
            [$r, $stamp, $evidence] = $this->locked($seed, $id, $driver, function ($l, $op) use ($prepared, $driver, $stamp, $records): array {
                if ($l['stamp'] !== $stamp) { throw new \DomainException('Management authority changed during preparation.'); }
                if ($op->state !== 'running' || ($op->attempt['seal'] ?? null) !== 'absent') { throw new \DomainException('Management claim changed.'); }
                $this->peers($l, $op->id, $op->policy['management']['correction'] ?? null, $op->action); $this->features->requireManagement();
                $this->noInflight($op);
                ManagementPreparation::requireFrozen($op, $prepared);
                GlueRecords::prepared($l['domain'],$records,$this->clock->now());
                $op->update(['payload' => $prepared]);
                return [$this->descriptor($op, $l), HoldingLocks::take($l['domain'], (int)$op->authorized_by)['stamp'], self::evidence($l['domain'])];
            });
            $this->grant = new ManagementGrant($r, $ctx, $driver, $stamp, $seed, $id, $token);
            try { $result = $driver->perform($r, $ctx, $this); }
            finally {
                $read = $this->receiptGrant?->read;
                $this->grant = $this->receiptGrant = null;
            }
            if (!$read) { throw new \DomainException('Management send was not sealed.'); }
            return $this->finish($seed, $id, $driver, $result, $read, evidence: $evidence);
        } catch (\Throwable $error) {
            return $this->stopWorker($seed, $id, $driver, $token, $read, $error, $refusals);
        }
    }

    private function expireRunning(Domain $seed, int $id): Operation
    {
        return FreshTransaction::run(function () use ($seed, $id): Operation {
            $op = HoldingLocks::take($seed)['operations']->firstWhere('id', $id);
            if ($op->state === 'running' && (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now())) {
                $facts = $op->managementAttempt()?->facts;
                if ($facts?->current && !$facts->sealed()) { $this->attempts->stop($op, $facts->token); }
                $op->update(['state'=>($op->attempt['seal']??null)==='sealed'?'uncertain':'held',
                    'safe_code'=>'management_unconfirmed', ...($facts ? [] : ['lease_expires_at'=>null])]);
                ManagementEvidence::finishExhausted($op, $this->clock->now());
            }
            return $op;
        });
    }

    private function resumePreparation(Domain $seed, int $id): Operation
    {
        return FreshTransaction::run(function()use($seed,$id){
                $l=HoldingLocks::take($seed);$op=$l['operations']->firstWhere('id',$id);
                $history=$op->attempt['management_deferrals']??[];
                $last=$history?new \DateTimeImmutable(end($history)):$op->started_at;
                if(ManagementEvidence::recoverable($op)&&$last&&$last<=$this->clock->now()->modify('-15 minutes')){
                    $this->attempts->restoreDeferred($op);
                    $op->update(['state'=>'queued','safe_code'=>'management_deferred']);
                }return $op;
        });
    }

    private function stopWorker(Domain $seed, int $id, ManagementProtocol $driver, ?AttemptToken $token,
        ?ReadToken $read, \Throwable $error, int $refusals): Operation
    {
        return FreshTransaction::run(function () use ($seed, $id, $driver, $token, $read, $error, $refusals): Operation {
            $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
            if (!$op) { throw new \DomainException('Management unavailable.'); }
            if ($read) { $this->attempts->close($read); }
            $facts = $token ? OperationAttempts::attempt($token, true) : null;
            if (!$facts?->current || $op->state !== 'running') { return $op; }

            $b = AccountBindings::binding($l['account']); $sealed = $facts->sealed();
            $unsent = $sealed && $op->funding_basis === 'non_billable_management' && $b->registrarKey === 'namesilo'
                && $driver->bindsTo($b) && $driver->unconsumed($id);
            $cap = $this->testManagement && (!$sealed && EnomTestCalls::refusedSince($refusals)
                || $op->funding_basis === 'non_billable_management' && $error instanceof EnomTestCallLimit
                    && EnomTestWriteReservation::unconsumed($b, 'management', $id));
            if ($unsent || $cap) {
                $this->attempts->stop($op, $token, refusal: $unsent ? 'unsent' : 'test_capacity');
                $op->update(['state'=>'held', 'safe_code'=>$unsent ? 'management_definitely_unsent' : 'enom_test_management_call_cap']);
                return $op;
            }

            $history = $op->attempt['management_deferrals'] ?? [];
            $defer = !$sealed && ($error instanceof ReadDeferred || $error instanceof WriteHeld && $error->reason === 'write_rate_limited')
                && count($history) < 5;
            $this->attempts->stop($op, $token, $defer);
            $op->update(['state'=>$sealed ? 'uncertain' : ($defer ? 'queued' : 'held'),
                'safe_code'=>$defer ? 'management_deferred' : 'management_unconfirmed']);
            ManagementEvidence::finishExhausted($op, $this->clock->now());
            return $op;
        });
    }
    public function consume(ManagementRequest $r, RequestContext $ctx, ManagementProtocol $driver): void
    {
        $g = $this->grant; $this->grant = null;
        if (!$g?->matches($r, $ctx, $driver)) { throw new \DomainException('No live management grant.'); }
        $read = $this->locked($g->seed, $g->operationId, $driver, function ($l, $op) use ($g, $ctx, $r, $driver): ReadToken {
            $this->features->requireManagement(); $this->peers($l, $op->id, $op->policy['management']['correction'] ?? null, $op->action);
            if (!$this->accepted || $l['stamp'] !== $g->stamp || $ctx->deadline < $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+25 seconds')
                || $op->state !== 'running' || ($op->attempt['seal'] ?? null) !== 'absent') { throw new \DomainException('Management authority changed.'); }
            $driver->requireAtSeal($r);
            $ctx->requireReservedCapacity();EnomTestWriteReservation::prepare($r->binding,'management');
            if($op->action==='auth_code_email'){foreach($l['operations'] as $peer){$at=$peer->attempt['dispatch_attempted_at']??null;if($peer->id!==$op->id&&$peer->action===$op->action&&is_string($at)&&new \DateTimeImmutable($at)>$this->clock->now()->modify('-10 minutes')){throw new \DomainException('Transfer code cooldown active.');}}}
            $this->noInflight($op); $funding=$op->funding_basis==='paid_invoice'?RefundPayment::requireCurrent($op,$l):[];
            if(!Cache::add('domains-write-slot:'.$op->account_fingerprint,true,$this->intervalSeconds)){throw new WriteHeld('write_rate_limited');}
            return $this->attempts->seal($op, $g->attempt, $ctx, $funding);
        });
        $this->receiptGrant = $g->sealed($read);
    }
    public function acknowledge(ManagementRequest $r, RequestContext $ctx, ManagementProtocol $driver, array $facts): void
    {
        $g=$this->receiptGrant;
        if (!$g?->read || !$g->matches($r, $ctx, $driver)){throw new \DomainException('No live acknowledgement grant.');}
        $this->locked($g->seed, $g->operationId, $driver,function($l,$op)use($facts,$g):void{
            if(!in_array($op->state,['running','uncertain'],true)||($op->attempt['seal']??null)!=='sealed'||array_key_exists('management_ack',$op->attempt)){throw new \DomainException('Acknowledgement unavailable.');}
            $valid=$op->action==='auth_code_email' ? $facts===['email_accepted'=>true] : in_array($op->action,['privacy_purchase','privacy_renew'],true)&&count($facts)===1&&is_string($facts['order_id']??null)&&preg_match('/^[1-9][0-9]{0,19}$/D',$facts['order_id']);
            // NameSilo is the only accepted durable profile creator; no generic inventory acknowledgement.
            if($op->action==='contact_profile'&&$op->registrar_key==='namesilo'){$valid=count($facts)===1&&is_string($facts['profile_id']??null)&&preg_match('/^[1-9][0-9]{0,19}$/D',$facts['profile_id']);}
            if($op->action==='dns' && isset($op->policy['management']['sequence_index'])) { $valid=count($facts)===1 && is_string($facts['record_id']??null) && preg_match('/^[a-zA-Z0-9]{1,128}$/D',$facts['record_id']); }
            if(!$valid){throw new \DomainException('Invalid management acknowledgement.');}
            $this->attempts->acknowledge($op, $g->read, $facts);
        },false,false);
    }
    private function descriptor(Operation $op, array $l): ManagementRequest
    { return new ManagementRequest(AccountBindings::binding($l['account']), $op->operation_key, $l['domain']->domain, $op->action, $op->payload ?? [], $l['domain']->provider_domain_id,$op->attempt['management_ack']['order_id']??null,$op->attempt['management_ack']['profile_id']??null, (int) $l['domain']->id, ManagementSequence::context($op,$l)); }
    private function locked(Domain $seed, int $id, ManagementProtocol $driver, \Closure $use, bool $processing = true, bool $authorize = true): mixed
    {
        return FreshTransaction::run(function () use ($seed, $id, $driver, $use, $processing, $authorize): mixed {
            $actor = Operation::findOrFail($id)->authorized_by; $l = HoldingLocks::take($seed, $actor);
            $op = $l['operations']->firstWhere('id', $id);
            if (!$op || !isset($op->policy['management']) || $op->authority_generation !== $l['domain']->authority_generation
                || $op->account_fingerprint !== $l['domain']->account_fingerprint || $op->service_id !== $l['domain']->service_id
                || $op->registrar_key !== $l['domain']->registrar_key || $op->server_id !== $l['domain']->server_id) { throw new \DomainException('Management binding changed.'); }
            if ($authorize) { ManagementSequence::requireCurrent($l, $op); $this->authority($l, (int)$op->authorized_by, $op->action, $driver, $processing, $op->policy['management']['correction'] ?? null);
                if (isset($op->policy['management']['correction'])) { ManagementCorrections::require($l, $op->action, $op->policy['management']['input'], $op->policy['management']['correction']); } } return $use($l, $op);
        });
    }
    private function authority(array $l, int $actor, string $action, ManagementProtocol $driver, bool $processing = true, ?string $correction = null, bool $advisory = false): void
    {
        $d = $l['domain']; $b = AccountBindings::binding($l['account']);
        if(DepartureChecks::suspected($d)&&in_array($action,DepartureChecks::REGISTRAR,true)){throw new \DomainException(DepartureChecks::PAUSED);}
        if($processing&&$this->localCheck&&($this->localCheck)($b)!==true){throw new ManagementDeferred('Management acceptance required.');}
        if($this->testManagement){EnomTestManagement::requireAction($b,$action,$correction!==null);}
        if(in_array($action,['privacy_purchase','privacy_renew'],true)){
            if($actor!==(int)$d->user_id){throw new \DomainException('Privacy billing is owner-only.');}
            $this->features->requireManagement(); if(!$this->accepted||$this->intervalSeconds<25||$this->intervalSeconds>86400){throw new \DomainException('Management acceptance required.');}
        }
        if ($actor !== (int) $d->user_id && (!$l['staff'] || !$l['staff']->hasPermission('admin.domains.manage'))) { throw new \DomainException('Management permission required.'); }
        if (!$driver->bindsTo($b) || !$driver->capabilities()->binding->matches($b)
            || $driver->capabilities()->get(self::capability($action))->support !== Support::Supported) { throw new \DomainException('Management capability unsupported.'); }
        if($advisory){$this->bindings->requireProcessing($b);}
        elseif ($processing) { $this->bindings->requireProcessingLocked($l['server'], $l['account']); }
        else { $this->bindings->requireReadableLocked($l['server'], $l['account']); }
        if ((($d->status !== 'active' || $l['service']->status !== 'active') && !ManagementCorrections::pending($l, $action, $correction)) || RenewalStop::blocksExisting($l)
            || ($d->renewal_policy['automatic_spend_paused'] ?? false)) { throw new \DomainException('Management lifecycle held.'); }
        if (in_array($action, ['dns', 'srv', 'ds_add', 'ds_delete'], true)
            && !filter_var($l['settings']->firstWhere('key', 'domains_dns_tools')?->value ?? false, FILTER_VALIDATE_BOOLEAN)) { throw new \DomainException('DNS tools disabled.'); }
    }
    private function noInflight(Operation $op): void
    {
        if ($op->funding_basis === 'paid_invoice' && AccountSpendingHolds::query(Operation::where('account_fingerprint',$op->account_fingerprint)->where('id','!=',$op->id))->orderBy('id')->lockForUpdate()->get(['id'])->isNotEmpty()) { throw new WriteHeld('write_rate_limited'); }
    }
    private function peers(array $l, ?int $except = null, ?string $correction = null, ?string $action = null): void
    { if ($this->unresolved($l, $except, $correction, $action) !== null) { throw new ManagementDeferred('Prior operation unresolved.'); } }
    /** Changes an unconfirmed auth code email can't affect: the email goes to the registrant, and these leave the registrant,
     * the transfer lock and the code alone. Contacts, the lock, privacy and another email still wait for it. */
    private const BESIDE_EMAIL=['nameservers','dns','srv','ds_add','ds_delete','glue_add','glue_update','glue_delete'];
    /** A sent auth code email whose acceptance is unknown. It is never resent or marked sent; it only stops holding unrelated changes. */
    private static function unconfirmedEmail(Operation $op): bool
    { return $op->action==='auth_code_email'&&$op->state==='uncertain'&&($op->attempt['seal']??null)==='sealed'; }
    /** An open renewal with nothing recorded on its attempt rows holds no registrar change; rows that no longer read hold it. */
    private static function renewalUntouched(Operation $op): bool
    { try { return RenewalAttempt::of($op)->untouched(); } catch (\Illuminate\Contracts\Encryption\DecryptException) { return false; } }
    private function unresolved(array $l, ?int $except = null, ?string $correction = null, ?string $action = null): ?Operation
    {
        $b = AccountBindings::binding($l['account']);
        foreach ($l['operations'] as $op) {
            if ($correction !== null && $op->operation_key === $correction && ManagementCorrections::pending($l, $action ?? '', $correction)) { continue; }
            if($op->id!==$except&&$op->action==='renew'&&in_array($op->state,['awaiting_payment','queued','held'],true)&&self::renewalUntouched($op)){continue;}
            if(in_array($action,self::BESIDE_EMAIL,true)&&self::unconfirmedEmail($op)){continue;}
            if ($op->id !== $except && !ManagementEvidence::resolved($op, $l['domain']) && !RenewalAuthority::resolved($op, $l['domain'], $b)) { return $op; }
        }
        return null;
    }
}
