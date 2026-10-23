<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AdmittedOperation, DateEvidence, Discrepancy, Disposition, DomainSnapshot, DriverResult, Evidence, ExpiryPrecision, Money, NameserverEvidence, RegistrationEvidence, RequestContext, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, PurchaseMapping, RegistrarRequest, ResponseParser};
use Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles;
final class Registration extends RegistrationProtocol
{
    public function __construct(AccountBinding $binding, private readonly RegistrationProfiles $profiles, #[\SensitiveParameter] \Closure $exchange)
    {
        if ($binding->registrarKey !== 'namesilo') { throw new \InvalidArgumentException('Wrong registration registrar.'); }
        parent::__construct($binding, $exchange);
    }
    public function usesProfiles(RegistrationProfiles $profiles): bool { return $this->profiles === $profiles; }
    protected function sendRegistration(AdmittedOperation $op, RequestContext $ctx, #[\SensitiveParameter] array $payload, string $origin, int $timeoutMs): DriverResult
    {
        $this->requireInFlight($op, $ctx, $payload, $origin, $timeoutMs);
        $roles = $this->profiles->wireRoles($op->operationKey, $op->desiredStateDigest);
        if (count(array_unique($roles)) !== 1) { throw new \DomainException('Registration requires one proven four-role profile.'); }
        $privacy = $payload['privacy'] ?? false;
        if (!is_bool($privacy)) { throw new \DomainException('Invalid privacy policy.'); }
        $parts = explode('.', $op->query->name);
        $nexus = \Paymenter\Extensions\Servers\Domains\Services\RegistryInputs::nameSiloNexus(end($parts), $payload['registry'] ?? null);
        $mapping = PurchaseMapping::register($op->query->name, $op->query->years, $privacy, $roles['registrant'], $payload['nameservers'] ?? [], $nexus);
        $parser = new ResponseParser;
        $submittedAt = $ctx->now();
        $purchase = $parser->parse($this->request($op, $ctx, $origin, $mapping->command, $mapping->parameters()), $mapping, '');
        if (!in_array($purchase->outcome, [Outcome::Success, Outcome::Fallback], true)) {
            return new DriverResult($op->binding, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved);
        }
        // Capture accepted 300/301/302 first. Follow-up errors may only add correction work.
        $corrections = $purchase->code === 301 ? ['nameservers'] : ($purchase->code === 302 ? ['contacts'] : []);
        $snapshot = null; $confirmed = []; $nameservers = null;
        try {
            $infoRequest = RegistrarRequest::domainInfo($op->query->name);
            $info = $parser->parse($this->request($op, $ctx, $origin, 'getDomainInfo', $infoRequest->parameters()), $infoRequest, '');
            if ($info->outcome === Outcome::Success) {
                $nameservers = self::readNameservers($info->value('nameservers'), $ctx);
                if ($info->value('auto_renew') !== 'No') {
                    // Fixed ancillary removal; never resubmit registerDomain.
                    $this->request($op, $ctx, $origin, 'removeAutoRenewal', ['domain' => $op->query->name]);
                    $correctedInfo = $parser->parse($this->request($op, $ctx, $origin, 'getDomainInfo', $infoRequest->parameters()), $infoRequest, '');
                    if ($correctedInfo->outcome === Outcome::Success) {
                        $nameservers = self::readNameservers($correctedInfo->value('nameservers'), $ctx) ?? $nameservers;
                        $info = $correctedInfo;
                    }
                }
                if ($info->outcome === Outcome::Success) {
                    $expiry = new DateEvidence($info->value('expires'), ExpiryPrecision::Date, 'namesilo_domain_info');
                    $snapshot = new DomainSnapshot($op->binding, $op->query->name, true, $expiry,
                        $info->value('auto_renew') === 'Yes', $info->value('private') === 'Yes', nameservers: $nameservers);
                    if ($snapshot->providerAutoRenew === false) { $confirmed[] = 'auto_renew'; }
                    if ($snapshot->privacy === $privacy) { $confirmed[] = 'privacy'; }
                    $actualContacts = $info->value('contact_ids'); $expectedContacts = $roles;
                    if (is_array($actualContacts)) {
                        ksort($actualContacts); ksort($expectedContacts);
                        if ($actualContacts === $expectedContacts) { $confirmed[] = 'contacts'; }
                    }
                    if ($nameservers !== null) {
                        $actualNs = $nameservers->nameservers;
                        $expectedNs = array_map('strtolower', $payload['nameservers']);
                        sort($actualNs); sort($expectedNs);
                        if ($actualNs === $expectedNs) { $confirmed[] = 'nameservers'; }
                    }
                }
            }
        } catch (\Throwable) { /* Durable acceptance must survive readback/deadline/transport errors. */ }
        if ($nameservers === null && ($payload['nameservers'] ?? [])) {
            try { $nameservers = NameserverEvidence::requested($payload['nameservers'], 'registration_request', $submittedAt); }
            catch (\Throwable) { /* Invalid submitted data cannot be shown as a nameserver fact. */ }
        }
        $corrections = [...$corrections, ...array_diff(['auto_renew', 'nameservers', 'contacts', 'privacy'], $confirmed)];
        $facts = new RegistrationEvidence($op->query, null, null, $snapshot, array_values(array_unique($corrections)), $nameservers);
        $charge = null;
        try { $charge = new Money($purchase->value('order_amount'), 'USD'); } catch (\Throwable) { /* Acceptance survives unusable charge evidence;22 correlates. */ }
        $discrepancies = array_map(static fn ($kind) => Discrepancy::from($kind), $facts->corrections);
        return new DriverResult($op->binding, $discrepancies ? Disposition::AcceptedWithDiscrepancy : Disposition::Accepted,
            SendState::Sent, $discrepancies ? ResultReason::Fallback : ResultReason::Acknowledged,
            new Evidence(domain: $snapshot, charge: $charge, registration: $facts), $discrepancies);
    }

    private static function readNameservers(mixed $rows, RequestContext $context): ?NameserverEvidence
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 2 || count($rows) > 13
            || count(array_filter($rows, static fn ($row) => is_array($row) && is_string($row['nameserver'] ?? null))) !== count($rows)) { return null; }
        try { return NameserverEvidence::observed(array_column($rows, 'nameserver'), 'namesilo_domain_info', $context->now()); }
        catch (\Throwable) { return null; }
    }
}
