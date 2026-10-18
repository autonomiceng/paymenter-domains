<?php
// Adapted from Enom 444f31c EnomApi::register and DomainOperations::send; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AdmittedOperation, Discrepancy, Disposition, DriverResult, Evidence, RegistrationEvidence, RequestContext, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Services\ContactDetails;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{RegistrationCommand, ResponseParser};
final class Registration extends RegistrationProtocol
{
    public function __construct(AccountBinding $binding, #[\SensitiveParameter] \Closure $exchange)
    {
        if ($binding->registrarKey !== 'enom') { throw new \InvalidArgumentException('Wrong registration registrar.'); }
        parent::__construct($binding, $exchange);
    }
    protected function sendRegistration(AdmittedOperation $op, RequestContext $ctx, #[\SensitiveParameter] array $payload, string $origin, int $timeoutMs): DriverResult
    {
        $this->requireInFlight($op, $ctx, $payload, $origin, $timeoutMs);
        $contacts = ContactDetails::validate('enom', $payload['contacts'] ?? null);
        $parts = explode('.', $op->query->name, 2); $name = ['SLD' => $parts[0], 'TLD' => $parts[1]];
        $parameters = $name + ['NumYears' => $op->query->years, 'UnLockRegistrar' => 0, 'RenewName' => 0,
            'EmailNotify' => 0, 'UseCreditCard' => 'no', 'QueueOrder' => 0, 'AllowQueuing' => 0, 'IgnoreNSFail' => 'No', 'UseDNS' => 'default'];
        $fields = ['first_name' => 'FirstName', 'last_name' => 'LastName', 'organization_name' => 'OrganizationName',
            'address1' => 'Address1', 'address2' => 'Address2', 'city' => 'City', 'state_province' => 'StateProvince',
            'postal_code' => 'PostalCode', 'country' => 'Country', 'email_address' => 'EmailAddress', 'phone' => 'Phone'];
        foreach ($contacts as $role => $contact) {
            foreach ($fields as $local => $wire) { $parameters[($role === 'auxbilling' ? 'AuxBilling' : ucfirst($role)).$wire] = $contact[$local]; }
        }
        $parser = new ResponseParser;
        $purchase = $parser->parse(RegistrationCommand::Purchase, $this->request($op, $ctx, $origin, 'Purchase', $parameters));
        if ($purchase->status !== 'success') { return new DriverResult($op->binding, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved); }
        $reference = $purchase->value('orderid');
        $reference = is_string($reference) && preg_match('/^[1-9][0-9]{0,19}$/D', $reference) ? $reference : null;
        $expiry = Expiry::read($purchase); $status = $purchase->value('orderstatus');
        $facts = new RegistrationEvidence($op->query, $reference, $expiry, null, []);
        if ($purchase->code === '1300' || (is_string($status) && in_array(strtolower($status), ['pending', 'processing', 'queued'], true))) {
            return new DriverResult($op->binding, Disposition::Pending, SendState::Sent, ResultReason::Processing, new Evidence(registration: $facts));
        }
        if ($purchase->code !== '200' || $reference === null || $expiry === null) {
            return new DriverResult($op->binding, Disposition::Unknown, SendState::Sent, ResultReason::Unresolved, new Evidence(registration: $facts));
        }
        $corrections = []; $servers = $payload['nameservers'] ?? [];
        if ($servers) {
            $corrections[] = 'nameservers';
            try {
                $ns = $name + self::nameservers($servers);
                $parser->parse(RegistrationCommand::Nameservers, $this->request($op, $ctx, $origin, 'ModifyNS', $ns));
                // Ack alone never clears correction work. GetDNS must exactly match desired state.
                $read = $parser->parse(RegistrationCommand::NameserverReadback, $this->request($op, $ctx, $origin, 'GetDNS', $name));
                $actual = $read->value('dns');
                if (is_string($actual)) { $actual = [$actual]; }
                if ($read->status === 'success' && is_array($actual) && array_is_list($actual)
                    && count(array_filter($actual, 'is_string')) === count($actual)) {
                    $actual = array_map(static fn ($host) => strtolower(rtrim($host, '.')), $actual);
                    $expected = array_values(self::nameservers($servers));
                    sort($actual); sort($expected);
                    if ($actual === $expected) { $corrections = []; }
                }
            } catch (\Throwable) { /* Accepted purchase survives every ancillary failure. */ }
        }
        $facts = new RegistrationEvidence($op->query, $reference, $expiry, null, $corrections);
        return new DriverResult($op->binding, $corrections ? Disposition::AcceptedWithDiscrepancy : Disposition::Accepted,
            SendState::Sent, $corrections ? ResultReason::Fallback : ResultReason::Acknowledged, new Evidence(registration: $facts),
            $corrections ? [Discrepancy::Nameservers] : []);
    }
    /** Frozen Enom hostname grammar, independent of NameSilo's external-host purchase rule. */
    public static function nameservers(array $servers): array
    {
        if (!$servers) { return []; }
        if (!array_is_list($servers) || count($servers) < 2 || count($servers) > 12) { throw new \InvalidArgumentException('Invalid nameservers.'); }
        $result = [];
        foreach ($servers as $i => $host) {
            if (!is_string($host) || strlen($host) > 253 || str_ends_with($host, '..')
                || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($host, '.')) {
                throw new \InvalidArgumentException('Invalid nameserver.');
            }
            $host = strtolower(rtrim($host, '.'));
            if (filter_var($host, FILTER_VALIDATE_IP)) { throw new \InvalidArgumentException('Invalid nameserver.'); }
            $result['NS'.($i + 1)] = $host;
        }
        if (count(array_unique($result)) !== count($result)) { throw new \InvalidArgumentException('Duplicate nameservers.'); }
        return $result;
    }

}
