<?php
// Adapted from Enom 444f31c DomainOperations/DomainManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ManagementProtocol};
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, ManagementResult, PrivacyMode, RequestBudget, RequestContext, RequestOrigin, Support};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Dormant engine boundary. All reads/writes outside locks; every request owns one durable attempt. */
final class DomainManagement
{
    private ?array $grant = null;
    private ?array $receiptGrant = null;
    public function __construct(private readonly AccountBindings $bindings, private readonly Clock $clock,
        private readonly WriteFeatures $features = new WriteFeatures, private readonly bool $accepted = false,
        private readonly int $intervalSeconds = 25) {}
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
    /** Read-only owner affordances use the same current predicates; this grants no request/send authority. */
    public function available(User $actor, Domain $seed, ManagementProtocol $driver, array $actions): array
    {
        if (!$actions) { return []; }
        try {
            return DB::transaction(function()use($actor,$seed,$driver,$actions):array {
                $l=HoldingLocks::take($seed,(int)$actor->id);
                $this->authority($l,(int)$actor->id,$actions[0],$driver); $this->peers($l);
                return array_values(array_filter($actions,function($action)use($l,$actor,$driver):bool {
                    if ($action==='auth_code_email'&&$this->emailCooling($l)) { return false; }
                    if (in_array($action,['privacy_purchase','privacy_renew'],true)) {
                        try { $this->authority($l,(int)$actor->id,$action,$driver); }
                        catch (\Throwable) { return false; }
                    }
                    return $driver->capabilities()->get(self::capability($action))->support===Support::Supported;
                }));
            });
        } catch (\Throwable) { return []; }
    }
    private function emailCooling(array $l): bool
    { return $l['operations']->contains(fn($op)=>$op->action==='auth_code_email'&&in_array($op->state,['queued','running','succeeded','uncertain'],true)&&$op->created_at->toDateTimeImmutable()>$this->clock->now()->modify('-10 minutes')); }
    public function request(User $actor, int $id, string $action, #[\SensitiveParameter] array $payload, string $nonce,
        ManagementProtocol $driver, string $reason = 'owner_request', ?string $correction = null): Operation
    {
        OperationClaims::outside(); self::capability($action);
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonce) || !in_array($reason,['owner_request','staff_correction'],true)) { throw new \DomainException('Invalid management request identity.'); }
        $seed = Domain::findOrFail($id); $paid = in_array($action, ['privacy_purchase', 'privacy_renew'], true); $terms = null; $initial = null;
        if ($paid) {
            [$request, $initial, $existing] = DB::transaction(function () use ($seed, $actor, $action, $nonce, $driver) {
                $l = HoldingLocks::take($seed, (int) $actor->id); $this->authority($l, (int) $actor->id, $action, $driver);
                if($driver->capabilities()->privacy!==PrivacyMode::Paid){throw new \DomainException('Paid privacy unsupported.');}
                $old = $l['operations']->firstWhere('operation_key', 'manage:'.$seed->id.':'.$nonce) ?? ManagementPrivacy::pending($l,$action);
                return [new ManagementRequest(CatalogueAcceptances::binding($l['account']), 'manage:'.$seed->id.':'.$nonce, $seed->domain, $action, [], $seed->provider_domain_id), $l['stamp'], $old];
            });
            if ($existing) { if ($existing->action !== $action || (int)$existing->authorized_by !== (int)$actor->id) { throw new \DomainException('Management key reused.'); } return $existing; }
            $terms = $driver->billingTerms($request, new RequestContext($request->binding, RequestOrigin::Interactive, $this->clock->now()->modify('+250 seconds'), new RequestBudget(10), $this->clock));
        }
        return DB::transaction(function () use ($seed, $actor, $action, $payload, $nonce, $driver, $reason, $terms, $initial, $correction): Operation {
            $l = HoldingLocks::take($seed, (int) $actor->id); $d = $l['domain']; $this->authority($l, (int) $actor->id, $action, $driver, correction: $correction);
            $clean = $terms ?? $driver->validate($action, $payload, $d->domain); $key = 'manage:'.$seed->id.':'.$nonce;
            $clean = $driver->freeze($key, $d->domain, $action, $clean, (int) $d->id);
            if ($correction !== null) { ManagementCorrections::require($l, $action, $clean, $correction); }
            $old = $l['operations']->firstWhere('operation_key', $key);
            if ($old) {
                if (($old->policy['management']['correction'] ?? null) !== $correction || $old->action !== $action || (int)$old->authorized_by !== (int) $actor->id || ($terms===null && ($old->policy['management']['input'] ?? null) !== $clean)) { throw new \DomainException('Management key reused.'); }
                return $old;
            }
            if($terms!==null&&($pending=ManagementPrivacy::pending($l,$action))){return $pending;}
            if ($initial !== null && $l['stamp'] !== $initial) { throw new \DomainException('Privacy authority changed.'); }
            $this->peers($l, correction: $correction, action: $action);
            if ($action === 'auth_code_email' && $this->emailCooling($l)) { throw new \DomainException('Transfer code cooldown active.'); }
            $policy = ['management' => ['actor_id' => (int) $actor->id, 'reason' => (int)$actor->id===(int)$d->user_id?'owner_request':'staff_correction', 'input' => $clean,
                'at' => $this->clock->now()->format(DATE_ATOM), 'generation' => $d->authority_generation, ...($correction !== null ? ['correction' => $correction, 'correction_link' => CorrectionProgress::link($l, $correction, $action)] : [])]];
            $data = ['domain_id' => $d->id, 'service_id' => $d->service_id, 'server_id' => $d->server_id, 'registrar_key' => $d->registrar_key,
                'account_fingerprint' => $d->account_fingerprint, 'authority_generation' => $d->authority_generation, 'authorized_by' => (int) $actor->id,
                'operation_key' => $key, 'action' => $action, 'term' => 1, 'currency' => 'USD', 'amount' => '0.00',
                'funding_basis' => 'non_billable_management', 'state' => 'queued', 'payload' => $clean, 'policy' => $policy];
            if (in_array($action, ['privacy_purchase', 'privacy_renew'], true)) { $data = ManagementPrivacy::invoice($data, $l, $driver, $this->clock); }
            return Operation::create($data);
        });
    }
    /** Local retirement only; paid or financially touched invoices require the refund boundary. */
    public function retire(User $actor,int $id): Operation
    {
        OperationClaims::outside();$seed=Operation::findOrFail($id)->domain()->firstOrFail();
        return DB::transaction(function()use($actor,$id,$seed):Operation{
            $l=HoldingLocks::take($seed,(int)$actor->id);$op=$l['operations']->firstWhere('id',$id);
            if((int)$actor->id!==(int)$l['domain']->user_id&&(!$l['staff']||!$l['staff']->hasPermission('admin.domains.manage'))){throw new \DomainException('Management permission required.');}
            if(!$op||!ManagementEvidence::resolved($op,$l['domain'])||!ManagementEvidence::idle($op)){throw new \DomainException('Management retirement unavailable.');}
            if(isset($op->attempt['management_terminal'])){return $op;}
            if($op->invoice_id){$invoice=$l['invoices']->firstWhere('id',$op->invoice_id);$item=$l['items']->firstWhere('id',$op->invoice_item_id);
                if(!$invoice||!$item||(int)$item->invoice_id!==(int)$invoice->id||(int)$invoice->user_id!==(int)$l['domain']->user_id||$invoice->currency_code!=='USD'||(string)$item->quantity!=='1'||PricingRule::minor($item->price)!==PricingRule::minor($op->amount)){throw new \DomainException('Privacy line changed.');}ManagementPrivacy::requireLine($op,$l,$invoice,$item);
                if((int)$actor->id!==(int)$l['domain']->user_id||!$invoice||!in_array($invoice->status,['pending','cancelled'],true)||$l['transactions']->contains('invoice_id',$invoice->id)){throw new \DomainException('Privacy financial activity requires review.');}
                $invoice->update(['status'=>'cancelled']);
            }
            $op->update(['state'=>'rejected','safe_code'=>'management_retired','completed_at'=>$this->clock->now(),
                'attempt'=>[...($op->attempt??[]),'seal'=>'absent','send_state'=>'definitely_unsent','management_terminal'=>['actor_id'=>(int)$actor->id,'at'=>$this->clock->now()->format(DATE_ATOM)]]]);return $op;
        });
    }
    public function run(int $id, ManagementProtocol $driver, RequestOrigin $origin = RequestOrigin::Interactive): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $op = Operation::findOrFail($id);
        if(!isset($op->policy['management'])){throw new \DomainException('Not a management operation.');}
        if ($op->state === 'running' && (!$op->lease_expires_at || $op->lease_expires_at <= $this->clock->now())) {
            return DB::transaction(function () use ($seed,$id) { $l=HoldingLocks::take($seed); $op=$l['operations']->firstWhere('id',$id); if($op->state==='running'&&(!$op->lease_expires_at||$op->lease_expires_at<=$this->clock->now())){$op->update(['state'=>($op->attempt['seal']??null)==='sealed'?'uncertain':'held','safe_code'=>'management_unconfirmed','lease_expires_at'=>null]);} return $op; });
        }
        if (!in_array($op->state, ['queued', 'awaiting_payment'], true) || (($op->attempt ?? []) !== []&&!ManagementEvidence::retryable($op))) { return $op; }
        if ($op->funding_basis === 'paid_invoice' && \App\Models\Invoice::find($op->invoice_id)?->status !== 'paid') { return $op; }
        $r = null; $claimed=false; $claim=$op->attempt['management_claim']??bin2hex(random_bytes(16));
        try {
            [$r, $stamp] = $this->locked($seed, $id, $driver, function ($l, $op) use ($driver,$claim): array {
                $this->features->requireManagement();
                if (!$this->accepted || $this->intervalSeconds < 25 || $this->intervalSeconds > 86400) { throw new \DomainException('Management acceptance required.'); }
                $this->peers($l, $op->id, $op->policy['management']['correction'] ?? null, $op->action);
                if (!ManagementEvidence::retryable($op) && ($op->payload??[]) !== ($op->policy['management']['input']??null)) { throw new \DomainException('Management desired state changed.'); }
                if ((($op->attempt ?? []) !== []&&!ManagementEvidence::retryable($op)) || !in_array($op->state, ['queued', 'awaiting_payment'], true)) { throw new \DomainException('Management attempt already exists.'); }
                if ($op->funding_basis === 'paid_invoice') { RefundPayment::requireCurrent($op, $l); $this->noInflight($op); }
                if(Cache::has('domains-write-slot:'.$op->account_fingerprint)){throw new WriteHeld('write_rate_limited');}
                $op->update(['payload'=>$op->policy['management']['input'],'state' => 'running', 'started_at' => $this->clock->now(), 'lease_expires_at' => $this->clock->now()->modify('+360 seconds'),
                    'attempt' => [...($op->attempt??[]),'seal' => 'absent', 'send_state' => 'definitely_unsent', 'management_claim'=>$claim]]);
                return [$this->descriptor($op, $l), HoldingLocks::take($l['domain'], (int)$op->authorized_by)['stamp']];
            });
            $claimed=true;
            $ctx = new RequestContext($r->binding, $origin, $this->clock->now()->modify('+300 seconds'), new RequestBudget(10), $this->clock);
            $prepared = $driver->prepare($r, $ctx);
            [$r, $stamp] = $this->locked($seed, $id, $driver, function ($l, $op) use ($prepared, $driver, $stamp): array {
                if ($l['stamp'] !== $stamp) { throw new \DomainException('Management authority changed during preparation.'); }
                if ($op->state !== 'running' || ($op->attempt['seal'] ?? null) !== 'absent') { throw new \DomainException('Management claim changed.'); }
                $this->peers($l, $op->id, $op->policy['management']['correction'] ?? null, $op->action); $this->features->requireManagement();
                $this->noInflight($op);
                ManagementPreparation::requireFrozen($op, $prepared);
                $op->update(['payload' => $prepared]);
                return [$this->descriptor($op, $l), HoldingLocks::take($l['domain'], (int)$op->authorized_by)['stamp']];
            });
            $this->grant = [$r, $ctx, $driver, $stamp, $seed, $id];
            try { $result = $driver->perform($r, $ctx, $this); }
            finally { $this->grant = null; $this->receiptGrant = null; }
            return $this->finish($seed, $id, $driver, $result, null, true);
        } catch (\Throwable $error) {
            return DB::transaction(function () use ($seed, $id, $claimed, $claim, $error): Operation {
                $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
                if ($claimed && $op && ($op->attempt['management_claim']??null)===$claim && $op->state === 'running') {
                    $history=$op->attempt['management_deferrals']??[];$defer=($op->attempt['seal']??null)==='absent'&&$error instanceof WriteHeld&&$error->reason==='write_rate_limited'&&count($history)<5;
                    $op->update(['state'=>($op->attempt['seal']??null)==='sealed'?'uncertain':($defer?'queued':'held'),'safe_code'=>$defer?'management_deferred':'management_unconfirmed','lease_expires_at'=>null,
                        'attempt'=>[...$op->attempt,...($defer?['management_deferrals'=>[...$history,$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)]]:[])]]);
                }
                return $op ?? throw new \DomainException('Management unavailable.');
            });
        }
    }
    public function consume(ManagementRequest $r, RequestContext $ctx, ManagementProtocol $driver): void
    {
        $g = $this->grant; $this->grant = null;
        if (!$g || $g[0] !== $r || $g[1] !== $ctx || $g[2] !== $driver) { throw new \DomainException('No live management grant.'); }
        $this->locked($g[4], $g[5], $driver, function ($l, $op) use ($g, $ctx): void {
            $this->features->requireManagement(); $this->peers($l, $op->id, $op->policy['management']['correction'] ?? null, $op->action);
            if (!$this->accepted || $l['stamp'] !== $g[3] || $ctx->deadline->getTimestamp() - $this->clock->now()->getTimestamp() < 25
                || $op->state !== 'running' || ($op->attempt['seal'] ?? null) !== 'absent') { throw new \DomainException('Management authority changed.'); }
            if($op->action==='auth_code_email'){foreach($l['operations'] as $peer){$at=$peer->attempt['dispatch_attempted_at']??null;if($peer->id!==$op->id&&$peer->action===$op->action&&is_string($at)&&new \DateTimeImmutable($at)>$this->clock->now()->modify('-10 minutes')){throw new \DomainException('Transfer code cooldown active.');}}}
            $this->noInflight($op); $funding=$op->funding_basis==='paid_invoice'?RefundPayment::requireCurrent($op,$l):[];
            if(!Cache::add('domains-write-slot:'.$op->account_fingerprint,true,$this->intervalSeconds)){throw new WriteHeld('write_rate_limited');}
            $op->update(['attempt'=>[...$op->attempt,'seal'=>'sealed','send_state'=>'possibly_sent','dispatch_attempted_at'=>$this->clock->now()->format(DATE_ATOM)],
                'funding'=>[...($op->funding??[]),...($funding?['seal'=>$funding]:[])]]);
        });
        $this->receiptGrant=[$r,$ctx,$driver,$g[4],$g[5]];
    }
    public function acknowledge(ManagementRequest $r, RequestContext $ctx, ManagementProtocol $driver, array $facts): void
    {
        $g=$this->receiptGrant;
        if(!$g||$g[0]!==$r||$g[1]!==$ctx||$g[2]!==$driver){throw new \DomainException('No live acknowledgement grant.');}
        $this->locked($g[3],$g[4],$driver,function($l,$op)use($facts):void{
            if(!in_array($op->state,['running','uncertain'],true)||($op->attempt['seal']??null)!=='sealed'||array_key_exists('management_ack',$op->attempt)){throw new \DomainException('Acknowledgement unavailable.');}
            $valid=$op->action==='auth_code_email' ? $facts===['email_accepted'=>true] : in_array($op->action,['privacy_purchase','privacy_renew'],true)&&count($facts)===1&&is_string($facts['order_id']??null)&&preg_match('/^[1-9][0-9]{0,19}$/D',$facts['order_id']);
            // NameSilo is the only accepted durable profile creator; no generic inventory acknowledgement.
            if($op->action==='contact_profile'&&$op->registrar_key==='namesilo'){$valid=count($facts)===1&&is_string($facts['profile_id']??null)&&preg_match('/^[1-9][0-9]{0,19}$/D',$facts['profile_id']);}
            if(!$valid){throw new \DomainException('Invalid management acknowledgement.');}
            $op->update(['attempt'=>[...$op->attempt,'management_ack'=>$facts]]);
        },false,false);
    }
    /** Explicit read-only reconciliation. Never calls prepare or perform, and cannot retry a write/email. */
    public function reconcile(int $id, ManagementProtocol $driver): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        [$r, $stamp] = $this->locked($seed, $id, $driver, function ($l, $op) use ($driver): array {
            if(count($op->attempt['management_reads']??[])>=20){throw new \DomainException('Management read budget exhausted.');}
            if ($op->state === 'running' && $op->lease_expires_at > $this->clock->now()) { throw new \DomainException('Management worker still active.'); }
            if (!in_array($op->state, ['uncertain', 'running'], true) || ($op->attempt['seal'] ?? null) !== 'sealed') { throw new \DomainException('No unresolved management readback.'); }
            $b=CatalogueAcceptances::binding($l['account']); if(!$driver->bindsTo($b)){throw new \DomainException('Read binding changed.');}
            $this->bindings->requireReadableLocked($l['server'],$l['account']);
            return [$this->descriptor($op, $l), $l['stamp']];
        }, false, false);
        $ctx = new RequestContext($r->binding, RequestOrigin::Background, $this->clock->now()->modify('+250 seconds'), new RequestBudget(10), $this->clock);
        try { $result = $driver->observe($r, $ctx); return $this->finish($seed, $id, $driver, $result, $stamp); }
        catch (\Throwable) { return Operation::findOrFail($id); }
    }
    private function finish(Domain $seed, int $id, ManagementProtocol $driver, ManagementResult $result, ?string $stamp = null, bool $writeResult = false): Operation
    {
        return $this->locked($seed, $id, $driver, function ($l, $op) use ($result, $stamp, $writeResult): Operation {
            if(!in_array($op->state,['running','uncertain'],true)||($op->attempt['seal']??null)!=='sealed'){throw new \DomainException('Management readback no longer applicable.');}
            if ($stamp !== null && $l['stamp'] !== $stamp) { throw new \DomainException('Management changed during readback.'); }
            $facts = $result->facts(); $ack = $op->attempt['management_ack'] ?? [];
            $email=$op->action==='auth_code_email'&&($ack['email_accepted']??null)===true&&($result->confirmed||$result->emailAccepted); $confirmed=$result->confirmed&&$op->action!=='auth_code_email';
            $ok = ($confirmed || $email) && ($op->action!=='contact_profile'||isset($ack['profile_id'])) && (!in_array($op->action,['privacy_purchase','privacy_renew'],true) || isset($ack['order_id']));
            $projection=$l['domain']->observation??[]; $sequence=($projection['management_sequence']??0)+1;
            $entry = ['sequence'=>$sequence,'operation_key' => $op->operation_key, 'generation' => $op->authority_generation, 'action' => $op->action,
                'at' => $this->clock->now()->format(DATE_ATOM), 'correction_link' => $op->policy['management']['correction_link'] ?? null, 'confirmed' => $confirmed, 'email_accepted' => $email, 'facts' => $result->facts()];
            $refused=$writeResult&&$result->refused&&!$confirmed&&!$email;$terminal=$refused&&$op->funding_basis==='non_billable_management';
            $history = $op->attempt['management_reads'] ?? [];
            if (count($history) >= 20) { throw new \DomainException('Management read budget exhausted.'); }
            $op->update(['state' => $ok ? 'succeeded' : ($terminal?'rejected':'uncertain'), 'safe_code' => $email ? 'auth_email_accepted' : ($ok ? 'readback_confirmed' : ($refused?'management_provider_refused':'management_unconfirmed')),
                'attempt' => [...$op->attempt, 'management_reads' => [...$history, $entry], ...($ack ? ['management_ack'=>$ack] : []),...($refused?['management_refusal'=>['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,'action'=>$op->action]]:[])], 'completed_at' => ($ok||$terminal) ? $this->clock->now() : null, 'lease_expires_at' => null]);
            $projection['management_sequence']=$sequence; $l['domain']->update(['observation'=>$projection]);
            return $op;
        }, false, false);
    }
    private function descriptor(Operation $op, array $l): ManagementRequest
    { return new ManagementRequest(CatalogueAcceptances::binding($l['account']), $op->operation_key, $l['domain']->domain, $op->action, $op->payload ?? [], $l['domain']->provider_domain_id,$op->attempt['management_ack']['order_id']??null,$op->attempt['management_ack']['profile_id']??null, (int) $l['domain']->id); }
    private function locked(Domain $seed, int $id, ManagementProtocol $driver, \Closure $use, bool $processing = true, bool $authorize = true): mixed
    {
        return DB::transaction(function () use ($seed, $id, $driver, $use, $processing, $authorize): mixed {
            $actor = Operation::findOrFail($id)->authorized_by; $l = HoldingLocks::take($seed, $actor);
            $op = $l['operations']->firstWhere('id', $id);
            if (!$op || !isset($op->policy['management']) || $op->authority_generation !== $l['domain']->authority_generation
                || $op->account_fingerprint !== $l['domain']->account_fingerprint || $op->service_id !== $l['domain']->service_id
                || $op->registrar_key !== $l['domain']->registrar_key || $op->server_id !== $l['domain']->server_id) { throw new \DomainException('Management binding changed.'); }
            if ($authorize) { $this->authority($l, (int)$op->authorized_by, $op->action, $driver, $processing, $op->policy['management']['correction'] ?? null);
                if (isset($op->policy['management']['correction'])) { ManagementCorrections::require($l, $op->action, $op->policy['management']['input'], $op->policy['management']['correction']); } } return $use($l, $op);
        });
    }
    private function authority(array $l, int $actor, string $action, ManagementProtocol $driver, bool $processing = true, ?string $correction = null): void
    {
        $d = $l['domain']; $b = CatalogueAcceptances::binding($l['account']);
        if(in_array($action,['privacy_purchase','privacy_renew'],true)){
            if($actor!==(int)$d->user_id){throw new \DomainException('Privacy billing is owner-only.');}
            $this->features->requireManagement(); if(!$this->accepted||$this->intervalSeconds<25||$this->intervalSeconds>86400){throw new \DomainException('Management acceptance required.');}
        }
        if ($actor !== (int) $d->user_id && (!$l['staff'] || !$l['staff']->hasPermission('admin.domains.manage'))) { throw new \DomainException('Management permission required.'); }
        if (!$driver->bindsTo($b) || !$driver->capabilities()->binding->matches($b)
            || $driver->capabilities()->get(self::capability($action))->support !== Support::Supported) { throw new \DomainException('Management capability unsupported.'); }
        if ($processing) { $this->bindings->requireProcessingLocked($l['server'], $l['account']); }
        else { $this->bindings->requireReadableLocked($l['server'], $l['account']); }
        if ((($d->status !== 'active' || $l['service']->status !== 'active') && !ManagementCorrections::pending($l, $action, $correction)) || $l['cancel']->contains('service_id', $d->service_id)
            || ($d->renewal_policy['automatic_spend_paused'] ?? false)) { throw new \DomainException('Management lifecycle held.'); }
        if (in_array($action, ['dns', 'srv', 'ds_add', 'ds_delete'], true)
            && !filter_var($l['settings']->firstWhere('key', 'domains_dns_tools')?->value ?? false, FILTER_VALIDATE_BOOLEAN)) { throw new \DomainException('DNS tools disabled.'); }
    }
    private function noInflight(Operation $op): void
    {
        if ($op->funding_basis === 'paid_invoice' && Operation::where('account_fingerprint',$op->account_fingerprint)->where('id','!=',$op->id)
            ->whereIn('state',['running','pending','uncertain'])->where(fn($q)=>$q->where('funding_basis','paid_invoice')->orWhereIn('action',['register','renew','transfer','privacy_purchase','privacy_renew']))->orderBy('id')->lockForUpdate()->get(['id'])->isNotEmpty()) { throw new WriteHeld('write_rate_limited'); }
    }
    private function peers(array $l, ?int $except = null, ?string $correction = null, ?string $action = null): void
    {
        $b = CatalogueAcceptances::binding($l['account']);
        foreach ($l['operations'] as $op) {
            if ($correction !== null && $op->operation_key === $correction && ManagementCorrections::pending($l, $action ?? '', $correction)) { continue; }
            if($op->id!==$except&&$op->action==='renew'&&in_array($op->state,['awaiting_payment','queued','held'],true)&&($op->attempt??[])===[]){continue;}
            if ($op->id !== $except && !ManagementEvidence::resolved($op, $l['domain']) && !RenewalAuthority::resolved($op, $l['domain'], $b)) { throw new \DomainException('Prior operation unresolved.'); }
        }
    }
}
