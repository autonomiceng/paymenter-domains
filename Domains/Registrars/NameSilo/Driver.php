<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, RegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, Capabilities, CostUnit, DateEvidence, Disposition,
    DomainSnapshot, DriverResult, Evidence, ExpiryPrecision, Money, PrivacyMode, Query, QuoteEvidence, ReconciliationResult,
    RejectionBasis, RequestContext, ResultReason, SendState, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DomainName, Outcome, RegistrarRequest as Request, RegistrarResult};

/** Named reads only. Construction/quotes do not prove provider identity, sale eligibility or renewal safety. */
final class Driver implements RegistrarDriver
{
    private readonly array $annualActions;
    /** Annual catalogue units/currency require independent account acceptance, default none. */
    public function __construct(private readonly Client $client, array $acceptedAnnualActions = [],
        private readonly Clock $clock = new SystemClock, private readonly int $freshSeconds = 300)
    {
        foreach ($acceptedAnnualActions as $action) {
            if (!in_array($action, ['register', 'renew', 'transfer'], true)) { throw new \InvalidArgumentException('Invalid annual price acceptance.'); }
        }
        if ($freshSeconds < 1 || $freshSeconds > 3600) { throw new \InvalidArgumentException('Invalid quote lifetime.'); }
        $copy = []; foreach ($acceptedAnnualActions as $action) { $copy[] = $action; } $this->annualActions = $copy;
    }
    public function binding(): AccountBinding { return $this->client->binding; }
    public function accountCapabilities(#[\SensitiveParameter] RequestContext $context): Capabilities
    {
        $this->binding()->requireMatch($context->binding);
        return new Capabilities($this->binding(), [], PrivacyMode::Free);
    }
    private function read(Request $request, #[\SensitiveParameter] RequestContext $context): RegistrarResult
    {
        $this->binding()->requireMatch($context->binding);
        try { return $this->client->read($request, $context); }
        catch (\RuntimeException) { return new RegistrarResult(Outcome::Unknown); }
    }
    private function neutral(RegistrarResult $reply, Evidence $facts = new Evidence): DriverResult
    {
        return $reply->outcome === Outcome::Success
            ? new DriverResult($this->binding(), Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, $facts)
            : $this->unknown($reply->code !== null ? SendState::Sent : SendState::PossiblySent);
    }
    private function unknown(SendState $sent = SendState::PossiblySent): DriverResult
    {
        return new DriverResult($this->binding(), Disposition::Unknown, $sent, ResultReason::Unresolved);
    }
    private function unsent(ResultReason $reason): DriverResult
    {
        return new DriverResult($this->binding(), Disposition::Rejected, SendState::DefinitelyUnsent, $reason,
            rejectionBasis: RejectionBasis::DefinitelyUnsent);
    }
    public function balance(#[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $reply = $this->read(Request::balance(), $context);
        if ($reply->outcome !== Outcome::Success) { return $this->neutral($reply); }
        try { return $this->neutral($reply, new Evidence(balance: new Money($reply->value('balance'), 'USD'))); }
        catch (\InvalidArgumentException) { return $this->unknown(SendState::Sent); }
    }
    public function availability(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $this->binding()->requireMatch($context->binding);
        if ($query->action === Action::Renew) { return $this->unsent(ResultReason::Unsupported); }
        $reply = $this->read(Request::availability([$query->name], $query->action === Action::Transfer), $context);
        if ($reply->outcome !== Outcome::Success) { return $this->neutral($reply); }
        foreach (['available', 'unavailable', 'invalid'] as $field) {
            foreach ($reply->value($field, []) as $row) {
                if ($row['domain'] === $query->name) {
                    return $this->neutral($reply, new Evidence(available: $field === 'available', premium: $row['premium'] ?? null));
                }
            }
        }
        return $this->unknown(SendState::Sent);
    }
    public function quote(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $this->binding()->requireMatch($context->binding);
        $parts = DomainName::parse($query->name);
        if ($query->years !== 1) { return $this->unsent(ResultReason::Unsupported); }
        if (!in_array($query->action->value, $this->annualActions, true)) { return $this->unsent(ResultReason::Unverified); }
        $reply = $this->read(Request::prices(), $context);
        if ($reply->outcome !== Outcome::Success) { return $this->neutral($reply); }
        $amount = $reply->value($parts['tld'].'.'.match ($query->action) {
            Action::Register => 'registration', Action::Renew => 'renew', Action::Transfer => 'transfer',
        });
        // No-price has no positive signal here: null/missing is a failed quote, never withdrawal.
        if (!is_string($amount)) { return $this->unknown(SendState::Sent); }
        try {
            $now = $this->clock->now();
            return $this->neutral($reply, new Evidence(quote: new QuoteEvidence($query, new Money($amount, 'USD'), CostUnit::Annual,
                'namesilo_account_annual', $now, $now->modify('+'.$this->freshSeconds.' seconds'))));
        } catch (\InvalidArgumentException) { return $this->unknown(SendState::Sent); }
    }
    public function readDomain(string $name, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        Query::requireName($name); $reply = $this->read(Request::domainInfo($name), $context);
        if ($reply->outcome !== Outcome::Success) { return $this->neutral($reply); }
        $expiry = null;
        try { $expiry = new DateEvidence($reply->value('expires'), ExpiryPrecision::Date, 'namesilo_domain_info'); }
        catch (\InvalidArgumentException) {}
        // Successful account-scoped getDomainInfo observes ownership, not this attempt's execution.
        return $this->neutral($reply, new Evidence(domain: new DomainSnapshot($this->binding(), $name, true, $expiry,
            $reply->value('auto_renew') === 'Yes', $reply->value('private') === 'Yes', $reply->value('locked') === 'Yes')));
    }
    public function renewalAssessment(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $this->binding()->requireMatch($context->binding);
        if ($query->action !== Action::Renew) { return $this->unsent(ResultReason::Unsupported); }
        $domain = $this->readDomain($query->name, $context);
        // Neither expiry nor Active proves ordinary/grace eligibility or disabled restoration.
        return new DriverResult($this->binding(), Disposition::Unknown, $domain->sendState, ResultReason::Unverified, $domain->evidence());
    }
    public function reconcile(AttemptEvidence $attempt, #[\SensitiveParameter] RequestContext $context): ReconciliationResult
    {
        $this->binding()->requireMatch($context->binding); $this->binding()->requireMatch($attempt->binding);
        return new ReconciliationResult($attempt, new DriverResult($this->binding(), Disposition::Unknown, SendState::PossiblySent,
            ResultReason::Unresolved, new Evidence(candidates: (new Correlation($this->client))->candidates($attempt, $context))));
    }
}
