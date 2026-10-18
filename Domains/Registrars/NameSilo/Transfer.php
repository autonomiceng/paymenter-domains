<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\TransferProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AdmittedOperation, Disposition, DriverResult, Evidence, RequestContext, ResultReason, SendState, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Outcome, PurchaseMapping, ResponseParser};
use Paymenter\Extensions\Servers\Domains\Services\RegistrationProfiles;
final class Transfer extends TransferProtocol
{
    public function __construct(AccountBinding $binding, private readonly RegistrationProfiles $profiles, #[\SensitiveParameter] \Closure $exchange)
    {
        if ($binding->registrarKey !== 'namesilo') { throw new \InvalidArgumentException('Wrong transfer registrar.'); }
        parent::__construct($binding, $exchange);
    }
    public function usesProfiles(RegistrationProfiles $profiles): bool { return $this->profiles === $profiles; }
    protected function sendRegistration(AdmittedOperation $op, RequestContext $ctx, #[\SensitiveParameter] array $payload, string $origin, int $timeoutMs): DriverResult
    {
        $this->requireInFlight($op, $ctx, $payload, $origin, $timeoutMs);
        $roles = $this->profiles->wireRoles($op->operationKey, $op->desiredStateDigest);
        if (count(array_unique($roles)) !== 1) { throw new \DomainException('Transfer requires one proven four-role profile.'); }
        $mapping = PurchaseMapping::transfer($op->query->name, $payload['epp'], $payload['privacy'] ?? false, $roles['registrant'], $payload['nameservers'] ?? []);
        $reply = (new ResponseParser)->parse($this->request($op, $ctx, $origin, $mapping->command, $mapping->parameters()), $mapping, '');
        if (!in_array($reply->outcome, [Outcome::Success, Outcome::Fallback], true)) {
            return new DriverResult($op->binding, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unresolved);
        }
        // 301/302 fallback acks remain pending; completion readback owns all correction work.
        return new DriverResult($op->binding, Disposition::Pending, SendState::Sent, ResultReason::Processing, new Evidence(transfer: TransferStatus::Pending));
    }
}
