<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AccountBindingMismatch, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{ContactProfile, Operation};
/** Fresh creation/readback only. No account-inventory adoption, shared update or provider-ID input. */
final class RegistrationProfiles
{
    private readonly \SensitiveParameterValue $exchange;
    private array $created = [];
    private readonly \SensitiveParameterValue $diagnostic;
    public function __construct(private readonly AccountBinding $binding, private readonly OperationClaims $claims,
        private readonly Clock $clock, #[\SensitiveParameter] \Closure $exchange, #[\SensitiveParameter] ?\Closure $diagnostic = null)
    { $this->exchange = new \SensitiveParameterValue($exchange); $this->diagnostic = new \SensitiveParameterValue($diagnostic); }
    public function prepare(Operation $op, RequestContext $ctx): void
    {
        OperationClaims::outside(); $this->binding->requireMatch($ctx->binding);
        if ($ctx->origin !== RequestOrigin::Background || $this->binding->registrarKey !== 'namesilo') { throw new \DomainException('Profile context unavailable.'); }
        [$binding, $owner] = $this->authority($op, 'authority_before');
        if ($this->hasProof($op)) { return; }
        $contacts = ContactDetails::validate('namesilo', $op->payload['contacts'] ?? null);
        if (count(array_unique(array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $contacts))) !== 1) {
            throw new WriteHeld('contact_profile_proof_required'); // Single purchase profile covers all four frozen roles.
        }
        $contact = $contacts['registrant']; $parameters = self::parameters($contact);
        $created = $this->request($ctx, 'contactAdd', $parameters);
        $id = $created['contact_id'] ?? null;
        if (is_int($id)) { $id = (string) $id; }
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,19}$/D', $id)) { throw new WriteHeld('contact_profile_proof_required'); }
        try{$read = $this->request($ctx, 'contactList', ['contact_id' => $id]);}catch(ReadDeferred){throw new WriteHeld('contact_profile_proof_required');} $rows = $read['contact'] ?? null;
        if (is_array($rows) && !array_is_list($rows)) { $rows = [$rows]; }
        $row = is_array($rows) && count($rows) === 1 ? $rows[0] : [];
        if ((string) ($row['contact_id'] ?? '') !== $id || !in_array($row['default_profile'] ?? null, [0, '0'], true)) {
            throw new WriteHeld('contact_profile_proof_required');
        }
        $fields = ['fn' => 'first_name', 'ln' => 'last_name', 'cp' => 'company', 'ad' => 'address', 'ad2' => 'address2',
            'cy' => 'city', 'st' => 'state', 'zp' => 'zip', 'ct' => 'country', 'em' => 'email', 'ph' => 'phone'];
        foreach ($fields as $wire => $field) {
            if ($field === 'phone'
                ? !\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\ContactReadback::phoneMatches($row[$field] ?? null, $parameters[$wire], $parameters['ct'])
                : ($row[$field] ?? null) !== $parameters[$wire]) { throw new WriteHeld('contact_profile_proof_required'); }
        }
        // Provider work is over. Recheck current claim before retaining its exact owner/payload proof.
        $this->authority($op, 'authority_after');
        $profile = ContactProfiles::recordCreated($binding, $owner, $id, $contact, false,
            ['operation' => $op->operation_key, 'payload' => OperationClaims::payloadDigest($op), 'observed_at' => $ctx->now()->format(DATE_ATOM)]);
        $this->created[$op->operation_key] = (int) $profile->id;
    }
    /** Observer receives only a fixed predicate name and boolean, never claim/contact data. */
    private function authority(Operation $op, string $predicate): array
    {
        try { $result = $this->claims->profileAuthority($op); $this->binding->requireMatch($result[0]); }
        catch (ProfileAuthorityMismatch|AccountBindingMismatch $error) { $this->signal($predicate, false); throw $error; }
        $this->signal($predicate, true); return $result;
    }
    private function signal(string $predicate, bool $passed): void
    { if ($observer = $this->diagnostic->getValue()) { $observer($predicate, $passed); } }
    public function hasProof(Operation $op): bool
    {
        try { $this->profiles($op); return true; } catch (\Throwable) { return false; }
    }
    public function wireRoles(string $operationKey, string $digest): array
    {
        $op = Operation::where('operation_key', $operationKey)->sole();
        if (OperationClaims::payloadDigest($op) !== $digest) { throw new \DomainException('Profile payload changed.'); }
        $profile = $this->profiles($op);
        return ContactProfiles::roles($this->binding, (int) $op->domain->user_id, array_fill_keys(ContactDetails::ROLES, (int) $profile->id));
    }
    private function profiles(Operation $op): ContactProfile
    {
        if ($op->account_fingerprint !== $this->binding->fingerprint) { throw new \DomainException('Profile binding changed.'); }
        $expected = ContactDetails::validate('namesilo', $op->payload['contacts'])['registrant'];
        // Local handle is obtained only from this worker's fresh creation; no inventory scan/adoption.
        $profile = ContactProfile::whereKey($this->created[$op->operation_key] ?? 0)
            ->where('server_id', $this->binding->serverId)->where('account_fingerprint', $this->binding->fingerprint)
            ->where('user_id', $op->domain->user_id)->firstOrFail();
        $proof = $profile->details['registration_proof'] ?? []; $date = $proof['observed_at'] ?? '';
        $observed = new \DateTimeImmutable($date);
        if ($date === '' || $observed > $this->clock->now() || $observed <= $this->clock->now()->modify('-120 seconds')
            || ($proof['operation'] ?? null) !== $op->operation_key || ($proof['payload'] ?? null) !== OperationClaims::payloadDigest($op)
            || $profile->details['contact'] !== $expected) { throw new WriteHeld('contact_profile_proof_required'); }
        return $profile;
    }

    private function request(RequestContext $ctx, string $command, #[\SensitiveParameter] array $parameters): array
    {
        OperationClaims::outside();
        $origin = ($this->binding->environment === \Paymenter\Extensions\Servers\Domains\Data\Environment::Live
            ? \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Live
            : \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Sandbox)->origin().'/apibatch';
        $send=fn($remaining)=>($this->exchange->getValue())($origin,$command,$parameters,min(25000,$remaining));
        $body=$ctx->withinRead($this->binding,$send);
        if (!is_string($body) || strlen($body) > 1048576) { throw new WriteHeld('contact_profile_proof_required'); }
        try { $data = json_decode($body, true, 24, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new WriteHeld('contact_profile_proof_required'); }
        if (($data['request']['operation'] ?? null) !== $command || !in_array($data['reply']['code'] ?? null, [300, '300'], true)) {
            throw new WriteHeld('contact_profile_proof_required');
        }
        return $data['reply'];
    }
    public static function parameters(#[\SensitiveParameter] array $c): array
    {
        return array_combine(['fn','ln','cp','ad','ad2','cy','st','zp','ct','em','ph'], array_values($c));
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Profile ports cannot be persisted.'); }
}
