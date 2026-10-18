<?php
// Adapted from autonomiceng/paymenter-enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, CanonicalName, Capabilities, Disposition, DomainSnapshot,
    DriverResult, Evidence, Money, PrivacyMode, Query, ReconciliationResult, RejectionBasis, RequestContext, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{Client, ProtocolResult, ReadCommand};

/** Named read adapter only; no admission, charging, persistence or account adoption. */
final class Driver implements RegistrarDriver
{
    private array $suffixes;
    public function __construct(private readonly Client $client, array $suffixes)
    {
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
    private function read(ReadCommand $command, array $parameters, #[\SensitiveParameter] RequestContext $context): ProtocolResult
    {
        $this->binding()->requireMatch($context->binding);
        try { return $this->client->read($command, $parameters, $context); }
        catch (\RuntimeException) { return new ProtocolResult('unknown', 'context_refused'); }
    }
    private function parts(string $name): array
    {
        Query::requireName($name); $parts = CanonicalName::parse($name, $this->suffixes);
        return ['SLD' => $parts['sld'], 'TLD' => $parts['tld']];
    }
    private function result(?array $facts): ReadResult { return new ReadResult($this->binding(), $facts === null ? 'unknown' : 'success', $facts ?? []); }
    public function balance(#[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $reply = $this->read(ReadCommand::Balance, [], $context);
        if ($reply->status !== 'success') { return $this->neutral($reply); }
        $value = $reply->value('availablebalance');
        if ($reply->status !== 'success' || !is_string($value)
            || !preg_match('/^(?:0|[1-9][0-9]*|[1-9][0-9]{0,2}(?:,[0-9]{3})+)(?:\.[0-9]{1,2})?$/D', $value)) {
            return $this->neutral(new ProtocolResult('unknown', 'invalid_payload'));
        }
        try { $balance = new Money(str_replace(',', '', $value).(str_contains($value, '.') ? '' : '.00'), 'USD'); }
        catch (\InvalidArgumentException) { return $this->neutral(new ProtocolResult('unknown', 'invalid_payload')); }
        return $this->neutral($reply, new Evidence(balance: $balance));
    }
    public function catalogue(#[\SensitiveParameter] RequestContext $context): ReadResult
    {
        $names = Catalogue::names($this->read(ReadCommand::Tlds, [], $context));
        return $this->result($names === null ? null : ['tlds' => $names]);
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
    /** Catalogue lexical value only. Exact quote/unit/currency authority arrives in slice 12. */
    public function productPrice(string $tld, string $action, int $years, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        Catalogue::tld($tld);
        $type = match ($action) { 'register' => 10, 'renew' => 16, 'transfer' => 19, default => throw new \InvalidArgumentException('Invalid price action.') };
        if ($years < 1 || $years > 10) { throw new \InvalidArgumentException('Invalid price term.'); }
        $reply = $this->read(ReadCommand::ProductPrice, ['TLD' => $tld, 'ProductType' => $type, 'Years' => $years], $context);
        if ($reply->status !== 'success') { return $this->result(null); }
        $enabled = $reply->value('productprice.productenabled'); $price = $reply->value('productprice.price');
        // Source EnomApi::productPrice's sole no-price condition; all other non-success reads fail.
        if (!is_string($enabled) || !in_array(strtolower($enabled), ['true', '1'], true) || !is_string($price) || !preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,4})?$/D', $price)) {
            return new ReadResult($this->binding(), 'no_price');
        }
        return $this->result(['tld' => $tld, 'action' => $action, 'years' => $years, 'price' => $price]);
    }
    public function availability(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        if ($query->action !== Action::Register) { return $this->unsupported($context); }
        $parts = $this->parts($query->name);
        $reply = $this->read(ReadCommand::Check, $parts + ['Version' => 2, 'IncludePrice' => 1, 'IncludeProperties' => 1, 'IncludeEAP' => 1], $context);
        if ($reply->status !== 'success') { return $this->neutral($reply); }
        $row = $reply->value('domains.domain');
        if (is_array($row) && array_is_list($row)) { $row = count($row) === 1 ? $row[0] : null; }
        if ($reply->status !== 'success' || !is_array($row) || !is_string($row['name'] ?? null) || strtolower($row['name']) !== $query->name || !in_array($row['rrpcode'] ?? null, ['210', '211'], true)) {
            return $this->neutral(new ProtocolResult('unknown', 'invalid_payload'));
        }
        // Premium includes platinum/EAP; missing any exclusion flag remains unknown.
        $flags = array_map(self::flag(...), [$row['ispremium'] ?? null, $row['isplatinum'] ?? null, $row['iseap'] ?? null]);
        $premium = in_array(true, $flags, true) ? true : (in_array(null, $flags, true) ? null : false);
        return $this->neutral($reply, new Evidence(available: $row['rrpcode'] === '210', premium: $premium));
    }
    /** @return array{Api\ProtocolResult, array, ?DomainSnapshot} Fresh account ownership proof before private reads. */
    private function identity(string $name, #[\SensitiveParameter] RequestContext $context): array
    {
        $parts = $this->parts($name); $reply = $this->read(ReadCommand::Info, $parts, $context);
        if ($reply->status !== 'success') { return [$reply, $parts, null]; }
        $info = $reply->value('getdomaininfo');
        $domain = is_array($info) ? ($info['domainname'] ?? null) : null;
        $label = is_array($domain) ? ($domain['#text'] ?? null) : $domain;
        $attributes = is_array($domain) ? ($domain['@attributes'] ?? []) : [];
        $status = is_array($info) ? ($info['status'] ?? null) : null;
        if (!is_array($status) || !is_string($label) || strtolower($label) !== $name || !is_array($attributes)
            || (isset($attributes['sld']) && (!is_string($attributes['sld']) || strtolower($attributes['sld']) !== $parts['SLD']))
            || (isset($attributes['tld']) && (!is_string($attributes['tld']) || strtolower($attributes['tld']) !== $parts['TLD']))) { return [new ProtocolResult('unknown', 'invalid_payload'), $parts, null]; }
        $owner = $status['belongs-to'] ?? null;
        if (is_array($owner)) { $owner = $owner['#text'] ?? null; }
        $id = $attributes['domainnameid'] ?? $info['domainnameid'] ?? null;
        if ((isset($attributes['domainnameid'], $info['domainnameid']) && $attributes['domainnameid'] !== $info['domainnameid'])
            || !is_string($owner) || !is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { return [new ProtocolResult('unknown', 'invalid_payload'), $parts, null]; }
        try { $owned = $this->binding()->matches(new AccountBinding('enom', $this->binding()->serverId, $this->binding()->environment, $owner)); }
        catch (\InvalidArgumentException) { return [new ProtocolResult('unknown', 'invalid_payload'), $parts, null]; }
        if (!$owned) { return [$reply, $parts, new DomainSnapshot($this->binding(), $name, false)]; }
        return [$reply, $parts, new DomainSnapshot($this->binding(), $name, true, Expiry::read($reply), providerReference: $id)];
    }
    public function readDomain(string $name, #[\SensitiveParameter] RequestContext $context): DriverResult
    {
        [$reply, $parts, $domain] = $this->identity($name, $context);
        if ($domain?->owned !== true) { return $this->neutral($reply, new Evidence(domain: $domain)); }
        $renew = $this->read(ReadCommand::Renewal, $parts, $context);
        $lock = $this->read(ReadCommand::Lock, $parts, $context);
        $privacy = $this->read(ReadCommand::Privacy, $parts, $context);
        $auto = self::flag($renew->value('auto-renew', $renew->value('renewname')));
        if ($renew->value('auto-renew') !== null && $renew->value('renewname') !== null && self::flag($renew->value('renewname')) !== $auto) { $auto = null; }
        $privacyFlag = self::flag($privacy->value('getwppsinfo.wppsenabled'));
        $allowed = self::flag($privacy->value('getwppsinfo.wppsallowed')); $exists = self::flag($privacy->value('getwppsinfo.wppsexists'));
        if ($allowed === null || $exists === null || ($privacyFlag === true && (!$allowed || !$exists))) { $privacyFlag = null; }
        $snapshot = new DomainSnapshot($this->binding(), $name, true, $domain->expiry, $auto,
            $privacyFlag, self::flag($lock->value('reg-lock')), $domain->providerReference);
        return $this->neutral($reply, new Evidence(domain: $snapshot));
    }
    public function expiry(string $name, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        [, $parts, $domain] = $this->identity($name, $context);
        if ($domain?->owned !== true) { return $this->result(null); }
        $reply = $this->read(ReadCommand::Expiry, $parts, $context);
        $expiry = Expiry::read($reply);
        return $this->result($expiry === null ? null : ['name' => $name, 'owned' => true, 'provider_reference' => $domain->providerReference, 'expiry' => $expiry]);
    }
    public function renewalInfo(string $name, #[\SensitiveParameter] RequestContext $context): ReadResult
    {
        [, $parts, $domain] = $this->identity($name, $context);
        if ($domain?->owned !== true) { return $this->result(null); }
        $reply = $this->read(ReadCommand::RenewalInfo, $parts, $context);
        $info = $reply->value('getextendinfo');
        if ($reply->status !== 'success' || !is_array($info) || array_is_list($info)) { return $this->result(null); }
        $maximum = $info['maxextension'] ?? null;
        return $this->result(['name' => $name, 'owned' => true, 'provider_reference' => $domain->providerReference,
            'expiry' => Expiry::read($reply), 'registrar_hold' => self::flag($info['registrarhold'] ?? null),
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
                ? SendState::PossiblySent : SendState::Sent, ResultReason::Unresolved);
    }
    private function unsupported(#[\SensitiveParameter] RequestContext $context): DriverResult
    {
        $this->binding()->requireMatch($context->binding);
        return new DriverResult($this->binding(), Disposition::Rejected, SendState::DefinitelyUnsent, ResultReason::Unsupported, rejectionBasis: RejectionBasis::DefinitelyUnsent);
    }
    public function quote(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult { return $this->unsupported($context); }
    public function renewalAssessment(Query $query, #[\SensitiveParameter] RequestContext $context): DriverResult { return $this->unsupported($context); }
    public function reconcile(AttemptEvidence $attempt, #[\SensitiveParameter] RequestContext $context): ReconciliationResult
    {
        $this->binding()->requireMatch($context->binding); $this->binding()->requireMatch($attempt->binding);
        return new ReconciliationResult($attempt, $this->neutral(new ProtocolResult('unknown', 'context_refused')));
    }
}
