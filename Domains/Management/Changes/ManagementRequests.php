<?php
// Adapted from Enom 444f31c DomainOperations/DomainManager; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Management\Changes;

use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest, PrivacyMode, RequestBudget, RequestContext, RequestOrigin, Support};
use Paymenter\Extensions\Servers\Domains\Holding\Observation\ObservationRecords;
use Paymenter\Extensions\Servers\Domains\Management\Records\OwnerRecords;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, EnomTestManagement, EntryDeadline, FreshTransaction,
    HoldingLocks, ManagementEvidence, OperationClaims, OwnedDomains, OwnerFormStale, PricingRule, PrivacyRetirement, Runtime,
    RuntimeIneligible, RuntimeReason};

trait ManagementRequests
{
    /** Read-only owner affordances use the same current predicates; this grants no request/send authority. $refused says why, when it can: SWITCH_OFF, the id of the request in the way, or the engine's refusal. */
    public function available(User $actor, Domain $seed, ManagementProtocol $driver, array $actions, int|string|null &$refused = null): array
    {
        if (!$actions) { return []; }
        try {
            return (function()use($actor,$seed,$driver,$actions,&$refused):array {
                $l=HoldingLocks::take($seed,(int)$actor->id,true);
                if($this->localCheck&&($this->localCheck)(AccountBindings::binding($l['account']))!==true
                    ||$this->testManagement&&!EnomTestManagement::accepted(AccountBindings::binding($l['account']))){$refused=self::SWITCH_OFF;return [];}
                if(($blocked=$this->unresolved($l))!==null){
                    $refused=(int)$blocked->id;
                    $actions=array_values(array_filter($actions,fn($action)=>$this->unresolved($l,action:$action)===null));
                    if(!$actions){return [];}
                }
                return array_values(array_filter($actions,function($action)use($l,$actor,$driver,&$refused):bool {
                    if ($action==='auth_code_email'&&$this->emailCooling($l)) { return false; }
                    try { $this->authority($l,(int)$actor->id,$action,$driver,advisory:true); }
                    // Orders off holds every action, so it is the reason over another action's own refusal; a request in the way stays first.
                    catch (\Throwable $refusal) { if(!is_int($refused)&&$refusal instanceof RuntimeIneligible&&$refusal->reason===RuntimeReason::ProcessingOff){$refused=$refusal->getMessage();} $refused??=$refusal->getMessage(); return false; }
                    return $driver->capabilities()->get(self::capability($action))->support===Support::Supported;
                }));
            })();
        } catch (\Throwable) { return []; }
    }
    private function emailCooling(array $l): bool
    { return $l['operations']->contains(fn($op)=>$op->action==='auth_code_email'&&in_array($op->state,['queued','running','succeeded','uncertain'],true)&&$op->created_at->toDateTimeImmutable()>$this->clock->now()->modify('-10 minutes')); }
    public function request(User $actor, int $id, string $action, #[\SensitiveParameter] array $payload, string $nonce,
        ManagementProtocol $driver, string $reason = 'owner_request', ?string $correction = null, ?array $recordIdentity = null, ?string $contactsDigest = null): Operation
    {
        return EntryDeadline::run($this->clock, fn ($deadline) => $this->requestEntered($actor, $id, $action, $payload, $nonce, $driver, $reason, $correction, $recordIdentity, $deadline, $contactsDigest), EntryDeadline::INTERACTIVE_SECONDS);
    }
    private function requestEntered(User $actor, int $id, string $action, #[\SensitiveParameter] array $payload, string $nonce,
        ManagementProtocol $driver, string $reason, ?string $correction, ?array $recordIdentity, \DateTimeImmutable $deadline, ?string $contactsDigest): Operation
    {
        OperationClaims::outside(); self::capability($action);
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonce) || !in_array($reason,['owner_request','staff_correction'],true)) { throw new \DomainException('Invalid management request identity.'); }
        $seed = Domain::findOrFail($id); $paid = in_array($action, ['privacy_purchase', 'privacy_renew'], true); $terms = null; $initial = null;
        if ($paid) {
            [$request, $initial, $existing] = FreshTransaction::run(function () use ($seed, $actor, $action, $nonce, $driver) {
                $l = HoldingLocks::take($seed, (int) $actor->id); $this->authority($l, (int) $actor->id, $action, $driver);
                if($driver->capabilities()->privacy!==PrivacyMode::Paid){throw new \DomainException('Paid privacy unsupported.');}
                $old = $l['operations']->firstWhere('operation_key', 'manage:'.$seed->id.':'.$nonce) ?? ManagementPrivacy::pending($l,$action);
                return [new ManagementRequest(AccountBindings::binding($l['account']), 'manage:'.$seed->id.':'.$nonce, $seed->domain, $action, [], $seed->provider_domain_id), $l['stamp'], $old];
            });
            if ($existing) { if ($existing->action !== $action || (int)$existing->authorized_by !== (int)$actor->id) { throw new \DomainException('Management key reused.'); } return $existing; }
            $terms = $driver->billingTerms($request, new RequestContext($request->binding, RequestOrigin::Interactive, $deadline, new RequestBudget(10), $this->clock, Runtime::limiter()));
        }
        [$plan, $sequenceStamp, $existing] = $this->planRequest($driver, $seed, $actor, $action, $payload, $nonce, $deadline, $paid, $correction);
        if ($existing) { return $existing; }
        return FreshTransaction::run(function () use ($seed, $actor, $action, $payload, $nonce, $driver, $reason, $terms, $initial, $correction, $recordIdentity, $plan, $sequenceStamp, $contactsDigest): Operation {
            $l = HoldingLocks::take($seed, (int) $actor->id); $d = $l['domain']; $this->authority($l, (int) $actor->id, $action, $driver, correction: $correction);
            if ($action === 'contacts' && $contactsDigest !== null && !hash_equals(OwnedDomains::contactsFormDigest($d, $l['operations']), $contactsDigest)) {
                throw new OwnerFormStale;
            }
            if ($sequenceStamp!==null && $sequenceStamp!==$l['stamp']) { throw new \DomainException('Management changed while reading inventory.'); }
            if ($recordIdentity!==null && (ObservationRecords::identity($d)!==$recordIdentity || !isset(OwnerRecords::forDomain($d)[$action]))) { throw new \DomainException('Current records required.'); }
            $clean = $terms ?? $driver->validate($action, $payload, $d->domain); $key = 'manage:'.$seed->id.':'.$nonce;
            $clean = $driver->freeze($key, $d->domain, $action, $clean, (int) $d->id);
            if ($correction !== null) { ManagementCorrections::require($l, $action, $clean, $correction); }
            $old = $l['operations']->firstWhere('operation_key', $key);
            if ($old) {
                if (($old->policy['management']['correction'] ?? null) !== $correction || $old->action !== $action || (int)$old->authorized_by !== (int) $actor->id || ($terms===null && ($old->policy['management']['intent'] ?? $old->policy['management']['input'] ?? null) !== $clean)) { throw new \DomainException('Management key reused.'); }
                return $old;
            }
            // The domain lock serializes both actors and also cleans up legacy duplicate queued choices.
            $pending=$l['operations']->sortByDesc('id')->filter(fn($peer)=>in_array($action,['lock','nameservers','contacts','contact_profile','dns','srv','auth_code_email'],true)
                && isset($peer->policy['management']) && !isset($peer->policy['management']['sequence_parent']) && ManagementProgress::setting($peer->action)===ManagementProgress::setting($action)
                && $peer->authority_generation===$d->authority_generation && $peer->account_fingerprint===$d->account_fingerprint
                && (ManagementProgress::pending($peer,$d) || ManagementSequence::unsent($peer,$l['operations'])));
            // A claimed/sealed write owns the setting, even if an older release queued a newer unsent choice.
            $unsent=fn($peer)=>!ManagementSequence::started($peer,$l['operations']) && ManagementEvidence::idle($peer) && !$peer->invoice_id && (($peer->attempt['seal']??'absent')==='absent' || $peer->safe_code==='management_definitely_unsent' && ($peer->attempt['management_unsent']??false)===true);
            $keep=$pending->first(fn($peer)=>!$unsent($peer))
                ?? $pending->first(fn($peer)=>$peer->action===$action && (!isset($peer->policy['management']['sequence']) || $peer->policy['management']['sequence']['configuration']===ManagementSequence::configuration($l)) && !ManagementSequence::unsent($peer,$l['operations']) && ($peer->policy['management']['intent']??$peer->policy['management']['input']??null)===$clean);
            foreach ($pending as $peer) {
                if ($peer->id===$keep?->id || !$unsent($peer)) { continue; }
                ManagementSequence::retirePrerequisite($peer,$l['operations'],(int)$actor->id,$this->clock->now());
                $at = $this->clock->now();
                ManagementAuthority::retire($peer, ['actor_id'=>(int)$actor->id, 'at'=>$at->format(DATE_ATOM)], $at);
            }
            if ($keep) { return $keep; }

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
            return ManagementSequence::create($data, $driver->prerequisite($action, $clean), $plan);
        });
    }
    private function planRequest(ManagementProtocol $driver, Domain $seed, User $actor, string $action,
        #[\SensitiveParameter] array $payload, string $nonce, \DateTimeImmutable $deadline, bool $paid, ?string $correction): array
    {
        $plan=null;$sequenceStamp=null;
        if ($driver->sequences($action)) {
            if ($paid || $correction!==null) { throw new \DomainException('Sequenced billing or correction unsupported.'); }
            [$input,$sequenceStamp,$binding,$configuration,$existing,$confirmed]=FreshTransaction::run(function()use($seed,$actor,$action,$payload,$nonce,$driver):array {
                $l=HoldingLocks::take($seed,(int)$actor->id);$this->authority($l,(int)$actor->id,$action,$driver);
                $input=$driver->validate($action,$payload,$l['domain']->domain);$key='manage:'.$seed->id.':'.$nonce;
                $input=$driver->freeze($key,$l['domain']->domain,$action,$input,(int)$seed->id);
                $old=$l['operations']->firstWhere('operation_key',$key);
                if ($old && ($old->action!==$action || (int)$old->authorized_by!==(int)$actor->id
                    || ($old->policy['management']['intent']??null)!==$input)) { throw new \DomainException('Management key reused.'); }
                return [$input,$l['stamp'],AccountBindings::binding($l['account']),ManagementSequence::configuration($l),$old,ManagementSequence::confirmed($l,$action,$input)];
            });
            if ($existing) { return [null, null, $existing]; }
            $r=new ManagementRequest($binding,'manage:'.$seed->id.':'.$nonce,$seed->domain,$action,$input,$seed->provider_domain_id,domainId:(int)$seed->id,
                sequence:$confirmed?['confirmed'=>$confirmed]:null);
            $plan=$driver->plan($r,new RequestContext($binding,RequestOrigin::Interactive,$deadline,new RequestBudget(10),$this->clock,Runtime::limiter()));
            $plan['configuration']=$configuration;
        }
        return [$plan, $sequenceStamp, null];
    }

    /** Local retirement only; paid or financially touched invoices require the refund boundary. */
    public function retire(User $actor,int $id): Operation
    {
        OperationClaims::outside();$seed=Operation::findOrFail($id)->domain()->firstOrFail();
        return FreshTransaction::run(function()use($actor,$id,$seed):Operation{
            $l=HoldingLocks::take($seed,(int)$actor->id);$op=$l['operations']->firstWhere('id',$id);
            if((int)$actor->id!==(int)$l['domain']->user_id&&(!$l['staff']||!$l['staff']->hasPermission('admin.domains.manage'))){throw new \DomainException('Management permission required.');}
            if(!$op||ManagementSequence::started($op,$l['operations'])||!ManagementEvidence::resolved($op,$l['domain'])||!ManagementEvidence::idle($op)){throw new \DomainException('Management retirement unavailable.');}
            if(isset($op->attempt['management_terminal'])){return $op;}
            if($op->invoice_id){$invoice=$l['invoices']->firstWhere('id',$op->invoice_id);$item=$l['items']->firstWhere('id',$op->invoice_item_id);
                if(!$invoice||!$item||(int)$item->invoice_id!==(int)$invoice->id||(int)$invoice->user_id!==(int)$l['domain']->user_id||$invoice->currency_code!=='USD'||(string)$item->quantity!=='1'||PricingRule::minor($item->price)!==PricingRule::minor($op->amount)){throw new \DomainException('Privacy line changed.');}ManagementPrivacy::requireLine($op,$l,$invoice,$item);
                if((int)$actor->id!==(int)$l['domain']->user_id||!$invoice||!in_array($invoice->status,['pending','cancelled'],true)||$l['transactions']->contains('invoice_id',$invoice->id)){throw new \DomainException('Privacy financial activity requires review.');}
                $invoice->update(['status'=>'cancelled']);
            }
            $at=$this->clock->now();$terminal=$op->invoice_id?PrivacyRetirement::terminal($op,$l['domain'],(int)$actor->id,$at):['actor_id'=>(int)$actor->id,'at'=>$at->format(DATE_ATOM)];
            ManagementSequence::retirePrerequisite($op,$l['operations'],(int)$actor->id,$at);
            ManagementAuthority::retire($op, $terminal, $at);
            return $op;
        });
    }
}
