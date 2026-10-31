<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, RegistrarDriver};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, Capabilities, CostUnit, DateEvidence, Disposition,
    DomainSnapshot, DriverResult, Evidence, ExpiryPrecision, Money, NameserverEvidence, PrivacyMode, Query, QuoteEvidence, ReconciliationResult,
    RejectionBasis, RenewalStatus, RequestContext, ResultReason, SendState, SystemClock};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DomainName, Outcome, RegistrarRequest as Request, RegistrarResult};

/** Named reads only. Construction/quotes do not prove provider identity, sale eligibility or renewal safety. */
final class Driver implements RegistrarDriver, \Paymenter\Extensions\Servers\Domains\Contracts\CatalogueReader, \Paymenter\Extensions\Servers\Domains\Contracts\TransferPoller
{
    public const ORDINARY_EXPIRY_MARGIN_DAYS = 2;
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
    /** Staff continuity review through the same bounded read client and transport. */
    public function defaultContact(RequestContext $context): array
    {
        $reply=$this->read(Request::defaultContact(),$context);
        $contact=$reply->value('default_contact');
        if($reply->outcome!==Outcome::Success||!is_array($contact)){throw new \DomainException('Could not read the current NameSilo default contact. Try again.');}
        return $contact;
    }
    /** One getPrices read: tld => {registration, renew, transfer}. */
    public function prices(RequestContext $context): array
    {
        $reply=$this->read(Request::prices(),$context);
        if($reply->outcome!==Outcome::Success){throw new \DomainException('Could not read NameSilo prices.');}
        return $reply->catalogueRows();
    }
    public function catalogueBatch(\Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance $accepted,
        \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness $freshness, RequestContext $context): array
    {
        $this->binding()->requireMatch($accepted->binding); $this->binding()->requireMatch($context->binding);
        $entries = [];
        $started = $context->now(); $reply = $this->read(Request::prices(), $context);
        if ($reply->outcome !== Outcome::Success) { throw new \DomainException('Catalogue batch read failed.'); }
        $rows = $reply->catalogueRows();
        foreach ($accepted->endings as $tld => $row) {
            if (!array_key_exists($tld,$rows)) { $entries[$tld]=null; continue; }
            if (array_key_exists($tld, $rows) && $rows[$tld] === null) {
                $entries[$tld] = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueUnavailable($this->binding(), $tld, $started);
                continue;
            }
            try {
                $rates = [];
                // Dotted suffix keys are literal provider keys, never dot-path traversal.
                $prices = $rows[$tld] ?? null;
                foreach (['register' => 'registration', 'renew' => 'renew', 'transfer' => 'transfer'] as $action => $key) {
                    foreach ($row[$action] as $term) {
                        $amount = $prices[$key] ?? null;
                        if (!is_string($amount)) { throw new \DomainException('No explicit account price.'); }
                        $rates[] = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueRate($action, $term, $amount,
                            'namesilo_account_annual', $started, $started->modify('+'.$freshness->maxAge().' seconds'));
                    }
                }
                $entries[$tld] = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry($this->binding(), $tld, $rates,
                    $row['register'], $row['registry'], $row['renewal_safe']);
            } catch (\Throwable $error) {
                $entries[$tld] = new \Paymenter\Extensions\Servers\Domains\Data\CatalogueFailure(\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::code($error,'catalogue_evidence_invalid'));
            }
        }
        return $entries;
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
        catch (\RuntimeException $error) { $context->requireReadSuccess(false,$error); return new RegistrarResult(Outcome::Unknown); }
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
        if ($query->years !== 1) { return $this->unsent(ResultReason::Unsupported); }
        if (!in_array($query->action->value, $this->annualActions, true)) { return $this->unsent(ResultReason::Unverified); }
        $reply = $this->read(Request::prices(), $context);
        if ($reply->outcome !== Outcome::Success) { return $this->neutral($reply); }
        $rows = $reply->catalogueRows();
        try { $parts = DomainName::parse($query->name, array_keys($rows)); }
        catch (\InvalidArgumentException) { return $this->unknown(SendState::Sent); }
        $amount = $rows[$parts['tld']][match ($query->action) {
            Action::Register => 'registration', Action::Renew => 'renew', Action::Transfer => 'transfer',
        }] ?? null;
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
        return $this->neutral($reply, new Evidence(domain: $this->snapshot($name, $reply, $context->now())));
    }
    private function snapshot(string $name, RegistrarResult $reply, \DateTimeImmutable $observedAt): DomainSnapshot
    {
        $expiry = null;
        try { $expiry = new DateEvidence($reply->value('expires'), ExpiryPrecision::Date, 'namesilo_domain_info'); }
        catch (\InvalidArgumentException) {}
        // Successful account-scoped getDomainInfo observes ownership, not this attempt's execution.
        return new DomainSnapshot($this->binding(), $name, true, $expiry,
            $reply->value('auto_renew') === 'Yes', $reply->value('private') === 'Yes', $reply->value('locked') === 'Yes',
            active: $reply->value('status') === 'Active', nameservers: self::readNameservers($reply->value('nameservers'), $observedAt));
    }
    private static function readNameservers(mixed $rows, \DateTimeImmutable $observedAt): ?NameserverEvidence
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 2 || count($rows) > 13
            || count(array_filter($rows, static fn ($row) => is_array($row) && is_string($row['nameserver'] ?? null))) !== count($rows)) { return null; }
        try { return NameserverEvidence::observed(array_column($rows, 'nameserver'), 'namesilo_domain_info', $observedAt); }
        catch (\Throwable) { return null; }
    }
    public function readRenewalDomain(string $name, #[\SensitiveParameter] RequestContext $context): DriverResult
    { return $this->readDomain($name, $context); }
    public function renewalAssessment(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $this->binding()->requireMatch($context->binding);
        if ($query->action !== Action::Renew) { return $this->unsent(ResultReason::Unsupported); }
        $reply = $this->read(Request::domainInfo($query->name), $context);
        if ($reply->outcome !== Outcome::Success) { return $this->neutral($reply); }
        $domain = $this->snapshot($query->name, $reply, $context->now());
        $facts = new Evidence(domain: $domain);
        // Only exact Active plus a safely future UTC calendar date excludes grace/restoration.
        $today = $context->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        if ($domain?->expiry && $reply->value('status') === 'Active'
            && $domain->expiry->value >= $today->modify('+'.self::ORDINARY_EXPIRY_MARGIN_DAYS.' days')->format('Y-m-d')) {
            $quote = $this->quote($query, $context);
            if ($quote->disposition !== Disposition::Accepted && $quote->sendState === SendState::PossiblySent) { return $quote; }
            if ($quote->disposition === Disposition::Accepted && $quote->evidence()->quote) {
                $q = $quote->evidence()->quote;
                // The margin must still hold when the engine finishes its unlocked read window.
                $until = (new \DateTimeImmutable($domain->expiry->value.'T00:00:00Z'))
                    ->modify('-'.(self::ORDINARY_EXPIRY_MARGIN_DAYS - 1).' days');
                if ($context->now() >= $until) {
                    return new DriverResult($this->binding(), Disposition::Unknown, SendState::Sent, ResultReason::Unverified, $facts);
                }
                $facts = new Evidence(domain: $domain, quote: new QuoteEvidence($q->query, $q->cost, $q->unit,
                    $q->source, $q->observedAt, min($q->validUntil, $until)), renewal: RenewalStatus::Ordinary);
                return new DriverResult($this->binding(), Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, $facts);
            }
        }
        // Premium remains unknown here; local completed nonpremium acquisition proof belongs to the engine.
        return new DriverResult($this->binding(), Disposition::Unknown,
            $reply->outcome === Outcome::Success ? SendState::Sent : $this->neutral($reply)->sendState, ResultReason::Unverified, $facts);
    }
    public function reconcile(AttemptEvidence $attempt, #[\SensitiveParameter] RequestContext $context): ReconciliationResult
    { return $this->reconcileAttempt($attempt,$context,false); }
    public function pollTransfer(AttemptEvidence $attempt, #[\SensitiveParameter] RequestContext $context): ReconciliationResult
    { return $this->reconcileAttempt($attempt,$context,true); }
    private function reconcileAttempt(AttemptEvidence $attempt,RequestContext $context,bool $pendingOnly): ReconciliationResult
    {
        $this->binding()->requireMatch($context->binding); $this->binding()->requireMatch($attempt->binding);
        $transfer = \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Unknown;
        if ($attempt->query->action === Action::Transfer) {
            $status = $this->read(Request::transferStatus($attempt->query->name), $context);
            if ($status->outcome === Outcome::Success) { $transfer = TransferState::read($status->value('status')); }
        }
        if($pendingOnly&&$transfer===\Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Pending){
            return new ReconciliationResult($attempt,new DriverResult($this->binding(),Disposition::Unknown,SendState::PossiblySent,
                ResultReason::Unresolved,new Evidence(transfer:$transfer)));
        }
        $history = (new Correlation($this->client))->inspect($attempt, $context);
        $domain = $this->readDomain($attempt->query->name, $context)->evidence()->domain;
        if ($transfer === \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Completed
            && array_key_exists('expiration', $status->catalogueRows())) {
            try {
                $expiry = new DateEvidence($status->value('expiration'), ExpiryPrecision::Date, 'namesilo_transfer_status');
                if ($expiry->value !== $domain?->expiry?->value) { $transfer = \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Unknown; }
            } catch (\InvalidArgumentException | \TypeError) { $transfer = \Paymenter\Extensions\Servers\Domains\Data\TransferStatus::Unknown; }
        }
        return new ReconciliationResult($attempt, new DriverResult($this->binding(), Disposition::Unknown, SendState::PossiblySent,
            ResultReason::Unresolved, new Evidence(domain: $domain, candidates: $history->candidates, historyComplete: $history->historyComplete, transfer: $transfer)));
    }
}
