<?php
// Enom 444f31c TransferOrders::assertReady; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\SealPreflight;
use Paymenter\Extensions\Servers\Domains\Data\{BalanceRetry, Action, Capabilities, DispatchReadiness, RequestContext, SaleSelection};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Both observations are unlocked. The second immediately precedes the seal and local consumption. */
final readonly class TransferPreflight implements SealPreflight
{
    public function __construct(private AccountBindings $bindings, private WriteFeatures $features, private WriteAcceptance $accepted,
        private TransferPrecheck $precheck, private ?RegistrationProfiles $profiles = null) {}
    public function usesPolicy(WriteFeatures $features, WriteAcceptance $accepted, $transport): bool
    {
        return $features === $this->features && $accepted === $this->accepted
            && (!$transport instanceof \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Transfer
                || ($this->profiles && $transport->usesProfiles($this->profiles) && $accepted->usesProfiles($this->profiles)));
    }
    private static function selection(Operation $op, Domain $d): SaleSelection
    { return new SaleSelection($d->tld, (int) $op->server_id, $op->registrar_key, $op->account_fingerprint, 0, $op->action, $op->term, 'USD', 0, 0); }
    public function inspect(Operation $op, Domain $domain, RequestContext $context): DispatchReadiness
    {
        OperationClaims::outside(); $query = OperationClaims::query($op, $domain); $this->features->requireEnabled(Action::Transfer);
        if ($query->action !== Action::Transfer || $query->years !== 1 || !TransferInputs::validCode($op->payload['epp'] ?? null)) { throw new \DomainException('Transfer inputs incomplete.'); }
        $retryBalance=null;
        if(BalanceRetry::from($op)?->window){
            $probe=$context->binding->registrarKey==='enom'&&EnomTestTransfer::enabled($context->binding)
                ?$this->bindings->registrationPreflightReader($context->binding):$this->bindings->reader($context->binding->serverId);
            $context->binding->requireMatch($probe->binding());$retryBalance=BalanceRetries::probe($op,$probe,$context);
        }
        ContactSetup::requireReady($op); $privacy = $op->payload['privacy'] ?? false; $servers = $op->payload['nameservers'] ?? [];
        if (!is_bool($privacy)) { throw new WriteHeld('order_not_supported'); }
        if ($context->binding->registrarKey === 'enom') {
            // Source PreConfig=0 keeps existing DNS; custom DNS/privacy need separate correction authority.
            if ($privacy || $servers) { throw new WriteHeld('order_not_supported'); }
        } else {
            \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::transfer($query->name, $op->payload['epp'], $privacy, '1', $servers);
            if (!$this->profiles) { throw new WriteHeld('contact_profile_proof_required'); }
            $this->accepted->requireCurrent($context->binding, $query, $op, profile: false);
            if (!$this->profiles->hasProof($op)) { $this->profiles->prepare($op, $context); }
        }
        $capability = $this->accepted->requireCurrent($context->binding, $query, $op);
        $reader = $context->binding->registrarKey==='enom'&&EnomTestTransfer::enabled($context->binding)?$this->bindings->registrationPreflightReader($context->binding):$this->bindings->reader($context->binding->serverId); $context->binding->requireMatch($reader->binding());
        $observed = $context->now(); $transfer = $this->precheck->check($query->name, self::selection($op, $domain), $reader, $context);
        $quote = $reader->quote($query, $context); $balance = $retryBalance??BalanceReads::read($reader, $context, 'preflight');
        WriteMoney::requireRead($context->binding, $quote, $balance);
        $q = $quote->evidence()->quote; WriteMoney::requireFunds($q, $balance->evidence()->balance, $query, $observed, $context->now());
        return new DispatchReadiness($context->binding, $query, new Capabilities($context->binding, ['transfer' => $capability]),
            $q->cost, true, false, $observed, min($q->validUntil, $observed->modify('+120 seconds')), OperationClaims::payloadDigest($op),
            [...$transfer, 'premium' => false, 'privacy' => $privacy, 'name' => $query->name, 'years' => 1, 'cost' => ['amount' => $q->cost->amount, 'currency' => 'USD']]);
    }
    public function requireAtSeal(Operation $op, Domain $domain, DispatchReadiness $ready): void
    {
        $this->features->requireEnabled(Action::Transfer); ContactSetup::requireReady($op);
        $this->accepted->requireCurrent($ready->binding, OperationClaims::query($op, $domain), $op, true);
        if (!$ready->transfer || $ready->transfer['rules'] !== TransferPrecheck::rules(self::selection($op, $domain), true)) { throw new \DomainException('Transfer rules changed.'); }
    }
}
