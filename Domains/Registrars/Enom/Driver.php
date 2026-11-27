<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, Capabilities, CatalogueRate, CostUnit, Discrepancy, Disposition, DomainSnapshot,
    DriverResult, Evidence, ExpiryPrecision, Money, NameserverEvidence, PrivacyMode, Query, QuoteEvidence, ReconciliationResult, TransferStatus, RejectionBasis, RequestContext, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Contract\CanonicalName;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ProtocolResult, ReadCommand};
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;

/** Named read adapter only; no admission, charging, persistence or account adoption. */
final class Driver implements RegistrarDriver, \Paymenter\Extensions\Servers\Domains\Contracts\AcquisitionPriceReader, \Paymenter\Extensions\Servers\Domains\Contracts\TransferPoller
{
    private array $suffixes;
    public function __construct(private readonly ReadAdapter $client, array $suffixes, private readonly int $priceMaxAgeSeconds = 300)
    {
        if ($priceMaxAgeSeconds < 1 || $priceMaxAgeSeconds > 86400) { throw new \InvalidArgumentException('Invalid local price freshness policy.'); }
        foreach ($suffixes as $tld) { if (!is_string($tld)) { throw new \InvalidArgumentException('Invalid ending.'); } Catalogue::tld($tld); }
        $this->suffixes = array_values(array_unique($suffixes));
    }

    public function binding(): AccountBinding { return $this->client->binding; }
    public function accountCapabilities(#[\SensitiveParameter] RequestContext $context): Capabilities
    {
        $this->binding()->requireMatch($context->binding);
        // Provider-wide paid privacy semantics survive; eligibility is a separate per-TLD read.
        return new Capabilities($this->binding(), [], PrivacyMode::Paid);
    }
    public static function flag(mixed $value): ?bool
    {
        return is_string($value) ? match (strtolower($value)) { '1', 'true', 'yes' => true, '0', 'false', 'no' => false, default => null } : null;
    }
    public static function autoRenew(ProtocolResult $renew): ?bool
    {
        if ($renew->status !== 'success') { return null; }
        $auto = self::flag($renew->value('auto-renew', $renew->value('renewname')));
        if ($renew->value('auto-renew') !== null && $renew->value('renewname') !== null && self::flag($renew->value('renewname')) !== $auto) { return null; }
        return $auto;
    }
    private function read(ReadCommand $command, array $parameters, #[\SensitiveParameter] RequestContext $context): ProtocolResult
    {
        $this->binding()->requireMatch($context->binding);
        try { return $this->client->read($command, $parameters, $context); }
        catch (\RuntimeException $error) { $context->requireReadSuccess(false,$error); return new ProtocolResult('unknown', 'context_refused'); }
    }
    private function parts(string $name): array
    {
        CanonicalName::requireCanonical($name); $parts = CanonicalName::parse($name, $this->suffixes);
        return ['SLD' => $parts['sld'], 'TLD' => $parts['tld']];
    }
    private function result(?array $facts): ReadResult { return new ReadResult($this->binding(), $facts === null ? 'unknown' : 'success', $facts ?? []); }
    public function balance(#[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $reply = $this->read(ReadCommand::Balance, [], $context);
        if ($reply->status !== 'success') { return $this->neutral($reply); }
        $balance = Balance::read($reply);
        if ($balance === null) { return $this->neutral(new ProtocolResult('unknown', 'invalid_payload')); }
        return $this->neutral($reply, new Evidence(balance: $balance));
    }
    public function catalogue(#[\SensitiveParameter] RequestContext $context): ReadResult
    {
        $names = Catalogue::names($this->read(ReadCommand::Tlds, [], $context));
        return $this->result($names === null ? null : ['tlds' => $names]);
    }
    /** Observation only: bulk reseller fields are not accepted cost evidence yet. */
    public function bulkPricing(bool $domainPricing, int $years, RequestContext $context): ReadResult
    {
        $started = hrtime(true);
        $reply = $this->read($domainPricing ? ReadCommand::DomainPricing : ReadCommand::RetailPricing,
            ($domainPricing ? ['UseQtyEngine' => 1] : ['TLDOnly' => 1]) + ['Years' => $years], $context);
        $rows = BulkPricing::rows($reply);
        return $this->result($rows === null ? null : ['rows' => $rows, 'response_bytes' => $reply->responseBytes,
            'seconds' => (hrtime(true) - $started) / 1000000000, 'max_period' => $reply->value('maxperiod')]);
    }
    public function tldCapabilities(string $tld, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        Catalogue::tld($tld);
        return $this->result(Catalogue::details($this->read(ReadCommand::TldInfo, ['TLD' => $tld], $context), $tld, $this->binding()));
    }
    public function registrySchema(string $tld, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        Catalogue::tld($tld); $reply = $this->read(ReadCommand::Attributes, ['TLD' => $tld], $context);
        if ($reply->status !== 'success') { return $this->result(null); }
        try { $schema = RegistryFields::normalize($reply); }
        catch (\Throwable) { return $this->result(null); }
        return $this->result(['fields' => $schema, 'supported' => RegistryFields::supported($schema)]);
    }
    /** Selected-term annual account rate; only explicit disablement means no price. */
    public function productPrice(string $tld, string $action, int $years, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        Catalogue::tld($tld);
        $type = match ($action) { 'register' => 10, 'renew' => 16, 'transfer' => 19, default => throw new \InvalidArgumentException('Invalid price action.') };
        if ($years < 1 || $years > 10) { throw new \InvalidArgumentException('Invalid price term.'); }
        $reply = $this->read(ReadCommand::ProductPrice, ['TLD' => $tld, 'ProductType' => $type, 'Years' => Pricing::bracket($years)], $context);
        if ($reply->status !== 'success') { return $this->result(null); }
        $enabled = $reply->value('productprice.productenabled'); $price = $reply->value('productprice.price');
        if (is_string($enabled) && in_array(strtolower($enabled), ['false', '0'], true)) {
            return new ReadResult($this->binding(), 'no_price');
        }
        // Malformed replies, including unsupported precision, retain prior costs.
        if (is_string($price) && preg_match('/^[0-9]+\.[0-9]{5,}$/D', $price)) { return $this->result(null); }
        $currency = $reply->value('productprice.currency');
        if ($currency !== null && (!is_string($currency) || strtoupper($currency) !== 'USD')) { return $this->result(null); }
        if (!is_string($enabled) || !in_array(strtolower($enabled), ['true', '1'], true) || !is_string($price) || Pricing::annual($price) === null) {
            return $this->result(null);
        }
        return $this->result(['tld' => $tld, 'action' => $action, 'years' => $years, 'price' => $price]);
    }
    public function availability(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        if (!in_array($query->action, [Action::Register, Action::Transfer], true) || ($query->action === Action::Transfer && $query->years !== 1)) { return $this->unsupported($context); }
        $parts = $this->parts($query->name);
        $reply = $this->read(ReadCommand::Check, $parts + ['Version' => 2, 'IncludePrice' => 1, 'IncludeProperties' => 1, 'IncludeEAP' => 1], $context);
        if ($reply->status !== 'success') { return $this->neutral($reply); }
        $row = $reply->value('domains.domain');
        if (is_array($row) && array_is_list($row)) { $row = count($row) === 1 ? $row[0] : null; }
        if ($reply->status !== 'success' || !is_array($row) || !is_string($row['name'] ?? null) || strtolower($row['name']) !== $query->name || !in_array($row['rrpcode'] ?? null, ['210', '211'], true)) {
            return $this->neutral(new ProtocolResult('unknown', 'invalid_payload'));
        }
        // Name-specific Check restrictions override the account/TLD catalogue. Missing bounds
        // retain the source 1..10 defaults; malformed or conflicting evidence is unknown.
        $properties = $row['properties'] ?? [];
        if ($properties === '') { $properties = []; }
        if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) { return $this->neutral(new ProtocolResult('unknown', 'invalid_payload')); }
        if ($query->action === Action::Register) {
        $min = $properties['minregyear'] ?? '1'; $max = $properties['maxregyear'] ?? '10';
        if (!is_string($min) || !is_string($max) || !preg_match('/^(?:[1-9]|10)$/D', $min)
            || !preg_match('/^(?:[1-9]|10)$/D', $max) || (int) $min > (int) $max) {
            return $this->neutral(new ProtocolResult('unknown', 'invalid_payload'));
        }
        if ($query->years < (int) $min || $query->years > (int) $max) { return $this->unsupported($context); }
        }
        // Premium includes platinum/EAP; missing any exclusion flag remains unknown.
        $flags = array_map(self::flag(...), [$row['ispremium'] ?? null, $row['isplatinum'] ?? null, $row['iseap'] ?? null]);
        $premium = in_array(true, $flags, true) ? true : (in_array(null, $flags, true) ? null : false);
        // A missing or unreadable Transferable stays unknown: only a stated no is a negative.
        return $this->neutral($reply, new Evidence(available: $query->action === Action::Transfer ? ($row['rrpcode'] === '211' ? self::flag($properties['transferable'] ?? null) : false) : $row['rrpcode'] === '210', premium: $premium));
    }
    /** @return array{Api\ProtocolResult, array, ?DomainSnapshot} Fresh account ownership proof before private reads. */
    private function identity(string $name, #[\SensitiveParameter] RequestContext $context): array
    {
        $parts = $this->parts($name); $reply = $this->read(ReadCommand::Info, $parts, $context);
        if ($reply->status !== 'success') { return [$reply, $parts, null]; }
        $domain = Identity::read($this->binding(), $name, $parts, $reply);
        if ($domain) { $context->sawDomain($domain); }
        return [$domain === null ? new ProtocolResult('unknown', 'invalid_payload') : $reply, $parts, $domain];
    }

    public function readDomain(string $name, #[\SensitiveParameter] RequestContext $context): DriverResult
    { return $this->domainFrom($name, $this->identity($name, $context), $context); }
    private function domainFrom(string $name, array $identity, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        [$reply, $parts, $domain] = $identity;
        if ($domain?->owned !== true) { return $this->neutral($reply, new Evidence(domain: $domain)); }
        $renew = $this->read(ReadCommand::Renewal, $parts, $context);
        if ($renew->status !== 'success') { return $this->neutral($renew, new Evidence(domain: $domain)); }
        $lock = $this->read(ReadCommand::Lock, $parts, $context);
        if ($lock->status !== 'success') { return $this->neutral($lock, new Evidence(domain: $domain)); }
        $privacy = $this->read(ReadCommand::Privacy, $parts, $context);
        if ($privacy->status !== 'success') { return $this->neutral($privacy, new Evidence(domain: $domain)); }
        $auto = self::autoRenew($renew);
        $privacyFlag = self::flag($privacy->value('getwppsinfo.wppsenabled'));
        $allowed = self::flag($privacy->value('getwppsinfo.wppsallowed')); $exists = self::flag($privacy->value('getwppsinfo.wppsexists'));
        if ($allowed === null || $exists === null || ($privacyFlag === true && (!$allowed || !$exists))) { $privacyFlag = null; }
        $nameservers = $context->readNameservers ? $this->read(ReadCommand::Nameservers, $parts, $context) : null;
        if ($nameservers?->status === 'success') {
            $context->retainObservationReplies($name, [
                ReadCommand::Info->value => $reply, ReadCommand::Nameservers->value => $nameservers,
                ReadCommand::Renewal->value => $renew, ReadCommand::Lock->value => $lock, ReadCommand::Privacy->value => $privacy,
            ]);
        }
        $snapshot = new DomainSnapshot($this->binding(), $name, true, $domain->expiry, $auto,
            $privacyFlag, self::flag($lock->value('reg-lock')), $domain->providerReference,
            nameservers: $nameservers ? self::readNameservers($nameservers, $context->now()) : null);
        return $this->neutral($reply, new Evidence(domain: $snapshot));
    }
    public function readRenewalDomain(string $name, #[\SensitiveParameter] RequestContext $context): DriverResult
    { return $this->renewalDomainFrom($name, $this->identity($name, $context), $context); }
    private function renewalDomainFrom(string $name, array $identity, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        [$reply, $parts, $domain] = $identity;
        if ($domain?->owned !== true) { return $this->neutral($reply, new Evidence(domain: $domain)); }
        $renew = $this->read(ReadCommand::Renewal, $parts, $context);
        if ($renew->status !== 'success') { return $this->neutral($renew, new Evidence(domain: $domain)); }
        $snapshot = new DomainSnapshot($this->binding(), $name, true, $domain->expiry, self::autoRenew($renew),
            providerReference: $domain->providerReference);
        return $this->neutral($reply, new Evidence(domain: $snapshot));
    }
    public function expiry(string $name, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        [, $parts, $domain] = $this->identity($name, $context);
        if ($domain?->owned !== true) { return $this->result(null); }
        $reply = $this->read(ReadCommand::Expiry, $parts, $context);
        $expiry = Expiry::read($reply);
        if ($expiry !== null) { $context->sawDomain(new DomainSnapshot($this->binding(), $name, true, $expiry, providerReference: $domain->providerReference)); }
        return $this->result($expiry === null ? null : ['name' => $name, 'owned' => true, 'provider_reference' => $domain->providerReference, 'expiry' => $expiry]);
    }
    public function renewalInfo(string $name, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        [, $parts, $domain] = $this->identity($name, $context);
        if ($domain?->owned !== true) { return $this->result(null); }
        $reply = $this->read(ReadCommand::RenewalInfo, $parts, $context);
        $expiry = Expiry::read($reply);
        if ($expiry === null) { return $this->result(null); }
        $maximum = $reply->value('maxextension');
        return $this->result(['name' => $name, 'owned' => true, 'provider_reference' => $domain->providerReference,
            'expiry' => $expiry, 'registrar_hold' => self::flag($reply->value('registrarhold')),
            'max_extension' => is_string($maximum) && preg_match('/^[0-9]{1,2}$/D', $maximum) && (int) $maximum <= 10 ? (int) $maximum : null,
            'grace_eligible' => null]);
    }
    public function contacts(string $name, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        [, $parts, $domain] = $this->identity($name, $context);
        if ($domain?->owned !== true) { return $this->result(null); }
        $contacts = Contacts::read($this->read(ReadCommand::Contacts, $parts, $context));
        return $this->result($contacts === null ? null : ['name' => $name, 'owned' => true, 'provider_reference' => $domain->providerReference] + $contacts);
    }
    /** Only named, validated read paths may acknowledge; never financial evidence. */
    private function neutral(ProtocolResult $reply, Evidence $facts = new Evidence): DriverResult
    {
        if ($facts->charge !== null || $facts->quote !== null || $facts->candidates) { throw new \LogicException('Invalid read evidence.'); }
        return $reply->status === 'success'
            ? new DriverResult($this->binding(), Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, $facts)
            : new DriverResult($this->binding(), Disposition::Unknown, in_array($reply->code, ['transport', 'context_refused'], true)
                ? SendState::PossiblySent : SendState::Sent, ResultReason::Unresolved, $facts, registrarCode:preg_match('/^[0-9]{3,9}$/D',(string)$reply->code)?(string)$reply->code:null);
    }
    private function unsupported(#[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $this->binding()->requireMatch($context->binding);
        return new DriverResult($this->binding(), Disposition::Rejected, SendState::DefinitelyUnsent, ResultReason::Unsupported, rejectionBasis: RejectionBasis::DefinitelyUnsent);
    }
    public function quote(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        if ($query->action === Action::Renew) { return $this->renewalAssessment($query, $context); }
        return $this->acquisitionQuote($query, $context);
    }

    /** Account price for acquisition and its future annual renewal, without owned-domain reads. */
    public function acquisitionQuote(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        if ($query->action === Action::Renew && $query->years !== 1) { return $this->unsupported($context); }
        if ($query->action === Action::Transfer && $query->years !== 1) { return $this->unsupported($context); }
        $parts = $this->parts($query->name);
        $price = $this->productPrice($parts['TLD'], $query->action->value, $query->years, $context);
        if ($price->status !== 'success') { return $this->neutral(new ProtocolResult('unknown', 'price_unconfirmed')); }
        $quote = new QuoteEvidence($query, Pricing::total(Pricing::annual($price->facts()['price']), $query->years), CostUnit::TermTotal,
            'enom_product_price', $now = $context->now(), $now->modify('+'.$this->priceMaxAgeSeconds.' seconds'));
        // Account/TLD price is advisory: it proves neither ordinary-name nor availability eligibility.
        return new DriverResult($this->binding(), Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, new Evidence(quote: $quote));
    }

    public function renewalAssessment(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        if ($query->action !== Action::Renew) { return $this->unsupported($context); }
        [$reply, $parts, $domain] = $this->identity($query->name, $context);
        if ($domain?->owned !== true) { return $this->neutral($reply, new Evidence(domain: $domain)); }
        $extend = $this->read(ReadCommand::RenewalInfo, $parts, $context);
        if ($extend->status !== 'success') { return $this->neutral($extend); }
        $check = $this->read(ReadCommand::Check, $parts + ['Version' => 2, 'IncludePrice' => 1, 'IncludeProperties' => 1, 'IncludeEAP' => 1], $context);
        if ($check->status !== 'success') { return $this->neutral($check); }
        $now = $context->now(); $expired = null; $lifecycle = null;
        if ($domain->expiry?->precision === ExpiryPrecision::Instant && new \DateTimeImmutable($domain->expiry->value) <= $now) {
            $expired = $this->read(ReadCommand::Expired, [], $context);
            if ($expired->status === 'success') { $lifecycle = $this->read(ReadCommand::TldInfo, ['TLD'=>$parts['TLD']], $context); }
        }
        if ($expired !== null && $expired->status !== 'success') { return $this->neutral($expired); }
        return new DriverResult($this->binding(), Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged,
            Renewal::assess($query, $domain, $reply, $extend, $check, $expired, $now, $now->modify('+'.$this->priceMaxAgeSeconds.' seconds'), $lifecycle, $parts['TLD']));
    }
    /** Actual charge read for an original attempt plus retained successful execution, never a purchase path. */
    public function orderCharge(AttemptEvidence $attempt, #[\SensitiveParameter] \Paymenter\Extensions\Servers\Domains\Data\PersistedExecution $execution,
        #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $this->binding()->requireMatch($context->binding); $this->binding()->requireMatch($attempt->binding);
        $id = $attempt->providerReference;
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { return $this->neutral(new ProtocolResult('unknown', 'order_unconfirmed')); }
        $order = $this->read(ReadCommand::Order, ['OrderID' => $id], $context);
        $candidate = OrderCharge::read($attempt, $execution, $order);
        if (!$candidate) { return $this->neutral(new ProtocolResult('unknown', 'order_unconfirmed')); }
        $context->sawCandidate($candidate);
        $expected = $attempt->expectedCharge;
        $difference = $expected && ($expected->currency !== 'USD' || rtrim(rtrim($expected->amount, '0'), '.') !== rtrim(rtrim($candidate->charge->amount, '0'), '.'));
        return new DriverResult($this->binding(), $difference ? Disposition::AcceptedWithDiscrepancy : Disposition::Accepted,
            SendState::Sent, $difference ? ResultReason::Fallback : ResultReason::Acknowledged,
            new Evidence(charge: $candidate->charge, candidates: [$candidate], billZoneSample: OrderCharge::billZoneSample($attempt, $order)), $difference ? [Discrepancy::Charge] : []);
    }
    public function reconcile(AttemptEvidence $attempt, #[\SensitiveParameter] RequestContext $context): ReconciliationResult
    { return $this->reconcileAttempt($attempt,$context,false); }
    public function pollTransfer(AttemptEvidence $attempt, #[\SensitiveParameter] RequestContext $context, bool $overdue = false): ReconciliationResult
    { return $this->reconcileAttempt($attempt,$context,true,$overdue); }
    private function transferStatus(AttemptEvidence $attempt,RequestContext $context): TransferStatus
    {
        if($attempt->query->action===Action::Transfer && is_string($attempt->transferReference) && preg_match('/^[1-9][0-9]{0,19}$/D',$attempt->transferReference)){
            return TransferState::read($attempt,$this->read(ReadCommand::Transfer,['TransferOrderID'=>$attempt->transferReference],$context));
        }
        return TransferStatus::Unknown;
    }
    private function reconcileAttempt(AttemptEvidence $attempt,RequestContext $context,bool $pendingOnly, bool $overdue = false): ReconciliationResult
    {
        $this->binding()->requireMatch($context->binding); $this->binding()->requireMatch($attempt->binding);
        $transfer=$pendingOnly?$this->transferStatus($attempt,$context):null;
        if ($pendingOnly && ($transfer === TransferStatus::Pending || ($overdue && $transfer === TransferStatus::Unknown))) {
            return new ReconciliationResult($attempt,new DriverResult($this->binding(),Disposition::Unknown,SendState::PossiblySent,
                ResultReason::Unresolved,new Evidence(transfer:$transfer,transferReference:$attempt->transferReference)));
        }
        $identity = $this->identity($attempt->query->name, $context);
        $domain = ($attempt->query->action === Action::Renew
            ? $this->renewalDomainFrom($attempt->query->name, $identity, $context)
            : $this->domainFrom($attempt->query->name, $identity, $context))->evidence()->domain;
        // Enom's exact not-found reply: the domain is not in this account. Recorded for a lost Register or Renew only.
        if ($domain === null && $identity[0]->code === 'domain_not_in_account' && in_array($attempt->query->action, [Action::Register, Action::Renew], true)) {
            $domain = new DomainSnapshot($this->binding(), $attempt->query->name, false);
        }
        if ($domain?->owned === true) {
            // The expiry read can fail or lag after the info read showed a renewed date; the context keeps that date.
            $expiry = $this->expiry($attempt->query->name, $context)->facts();
            $domain = new DomainSnapshot($this->binding(), $attempt->query->name, true,
                ($expiry['provider_reference'] ?? null) === $domain->providerReference ? ($expiry['expiry'] ?? null) : null,
                $domain->providerAutoRenew, $domain->privacy, $domain->locked, $domain->providerReference,
                active: $domain->active, nameservers: $domain->nameservers);
        }
        $transfer ??= $this->transferStatus($attempt,$context);
        $candidates = []; $sample = null;
        if ($attempt->executionFacts && $attempt->providerReference) {
            // The normal reply's own order detail, already read for its charge, also calibrates the Live bill clock.
            $charge = $this->orderCharge($attempt, $attempt->executionFacts, $context);
            $candidates = $charge->evidence()->candidates; $sample = $charge->evidence()->billZoneSample;
        }
        $complete = false; $scan = null;
        if ($attempt->replyLost() && $attempt->historyAcceptance?->covers($attempt, $context->now())) {
            $history = new History($this->client);
            $facts = $history->inspect($attempt, $context); $candidates = $facts->candidates; $complete = $facts->historyComplete; $scan = $facts->historyScan;
        }
        $proofFacts = new Evidence(domain: $domain, candidates: $candidates, historyComplete: $complete,
            transfer: $transfer, transferReference: $attempt->transferReference);
        if (ReconciliationEvidence::completion($attempt, $proofFacts, $context->now()) !== null
            && ($attempt->query->action === Action::Register
                || ($attempt->query->action === Action::Transfer && $transfer === TransferStatus::Completed))
            && $domain?->owned === true) {
            $reply = null;
            try {
                // The general read wrapper neutralizes RuntimeException; optional enrichment must expose unexpected failures.
                $reply = $this->client->read(ReadCommand::Nameservers, $this->parts($attempt->query->name), $context);
            } catch (\Paymenter\Extensions\Servers\Domains\Data\RequestDeadlineReached
                |\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred|\Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit) {
                // Expected local admission failures cannot discard proven completion.
            } catch (\DomainException $error) {
                // RequestContext uses this exact base exception for a refused provider read.
                if ($error::class !== \DomainException::class || $error->getMessage() !== 'Registrar read failed.') { throw $error; }
            } catch (\RuntimeException $error) {
                // Keep known request capacity failures neutral without hiding programming errors.
                if ($error::class !== \RuntimeException::class || !in_array($error->getMessage(),
                    ['Registrar request budget exhausted.', 'Registrar request deadline reached.'], true)) { throw $error; }
            }
            if ($reply !== null) {
                $nameservers = self::readNameservers($reply, $context->now());
                if ($nameservers) {
                    $domain = new DomainSnapshot($domain->binding, $domain->name, $domain->owned, $domain->expiry,
                        $domain->providerAutoRenew, $domain->privacy, $domain->locked, $domain->providerReference,
                        active: $domain->active, nameservers: $nameservers);
                }
            }
        }
        return new ReconciliationResult($attempt, new DriverResult($this->binding(), Disposition::Unknown,
            SendState::PossiblySent, ResultReason::Unresolved, new Evidence(domain: $domain, candidates: $candidates, historyComplete: $complete, transfer: $transfer, transferReference: $attempt->transferReference, historyScan: $scan, billZoneSample: $sample)));
    }
    private static function readNameservers(ProtocolResult $reply, \DateTimeImmutable $observedAt): ?NameserverEvidence
    {
        if ($reply->status !== 'success') { return null; }
        $rows = $reply->value('dns');
        if (is_string($rows)) { $rows = [$rows]; }
        if (!is_array($rows) || !array_is_list($rows) || count(array_filter($rows, 'is_string')) !== count($rows)) { return null; }
        try { return NameserverEvidence::observed($rows, 'enom_getdns', $observedAt); }
        catch (\InvalidArgumentException) { return null; }
    }
}
