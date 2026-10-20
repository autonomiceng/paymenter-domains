<?php
// Enom 444f31c TransferOrders::start; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Contracts\TransferProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AdmittedOperation, Disposition, DriverResult, Evidence, RequestContext, ResultReason, SendState, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Services\ContactDetails;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{RegistrationCommand, ResponseParser};
final class Transfer extends TransferProtocol
{
    public function __construct(AccountBinding $binding, #[\SensitiveParameter] \Closure $exchange)
    {
        if ($binding->registrarKey !== 'enom') { throw new \InvalidArgumentException('Wrong transfer registrar.'); }
        parent::__construct($binding, $exchange);
    }
    protected function sendRegistration(AdmittedOperation $op, RequestContext $ctx, #[\SensitiveParameter] array $payload, string $origin, int $timeoutMs): DriverResult
    {
        $this->requireInFlight($op, $ctx, $payload, $origin, $timeoutMs);
        $parts = explode('.', $op->query->name, 2);
        $parameters = ['OrderType' => 'Autoverification', 'DomainCount' => 1, 'SLD1' => $parts[0], 'TLD1' => $parts[1],
            'AuthInfo1' => $payload['epp'], 'PreConfig' => 0, 'Lock' => 1, 'Renew' => 0, 'UseCreditCard' => 'no'];
        $fields = ['first_name' => 'FirstName', 'last_name' => 'LastName', 'organization_name' => 'OrganizationName',
            'address1' => 'Address1', 'address2' => 'Address2', 'city' => 'City', 'state_province' => 'StateProvince',
            'postal_code' => 'PostalCode', 'country' => 'Country', 'email_address' => 'EmailAddress', 'phone' => 'Phone'];
        foreach (ContactDetails::validate('enom', $payload['contacts'] ?? null) as $role => $contact) {
            foreach ($fields as $local => $wire) { $parameters[($role === 'auxbilling' ? 'AuxBilling' : ucfirst($role)).$wire] = $contact[$local]; }
        }
        $reply = (new ResponseParser)->parse(RegistrationCommand::Transfer, $this->request($op, $ctx, $origin, 'TP_CreateOrder', $parameters));
        // Enom documents TP_CreateOrder with the ID under <transferorder>; a top-level ID is accepted only if it agrees.
        $nested = $reply->value('transferorder.transferorderid'); $top = $reply->value('transferorderid');
        $id = $nested !== null && $top !== null && $nested !== $top ? null : ($nested ?? $top);
        if ($reply->status !== 'success' || !is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
            return new DriverResult($op->binding, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved);
        }
        // TransferOrderID is not a billing OrderID. Charge discovery uses its own accepted history contract.
        return new DriverResult($op->binding, Disposition::Pending, SendState::Sent, ResultReason::Processing,
            new Evidence(transfer: TransferStatus::Pending, transferReference: $id));
    }
}
