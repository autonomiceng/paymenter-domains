<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, Capabilities, Capability, ManagementRequest, ManagementResult, PrivacyMode, RequestContext, RequestOrigin, Support};
use Paymenter\Extensions\Servers\Domains\Services\{ContactDetails, ManagementPayload, RegistrationProfiles, RegistryInputs};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{Environment, Outcome, RegistrarRequest, ResponseParser};
/** Baseline management only. No default sender, purchases, profile updates or advanced tools. */
final class Management extends ManagementProtocol
{
    public function __construct(AccountBinding $binding, #[\SensitiveParameter] \Closure $exchange)
    {
        if ($binding->registrarKey !== 'namesilo') { throw new \InvalidArgumentException('Wrong management registrar.'); }
        parent::__construct($binding, $exchange);
    }
    public function capabilities(): Capabilities
    {
        return new Capabilities($this->binding, array_fill_keys(['nameservers','contacts','lock','privacy','auto_renew_off','auth_code_email'], new Capability(Support::Supported))
            + array_fill_keys(['dns','ds','glue','auth_code_display'], new Capability(Support::Unsupported)), PrivacyMode::Free);
    }
    public function validate(string $action, #[\SensitiveParameter] array $payload, string $name): array
    {
        $this->writeCommand($action); // Unsupported actions refuse before any reads or invoices.
        if (in_array($action, ['contacts','contact_profile'], true)) {
            if (!is_array($payload['contact'] ?? null) || array_diff(array_keys($payload), ['role','contact']) || array_intersect(array_keys($payload['contact']), ['job_title','phone_ext','fax'])) {
                throw new \DomainException('Unsupported contact fields.');
            }
        }
        $clean = ManagementPayload::normalize($action === 'contact_profile' ? 'contacts' : $action, $payload, $name, 'namesilo');
        if ($action === 'nameservers') { foreach ($clean['nameservers'] as $host) {
            if (filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/[a-z]/', substr($host, strrpos($host, '.') + 1))) { throw new \DomainException('Invalid nameserver host.'); }
        } }
        return $clean;
    }
    public function freeze(string $key, string $name, string $action, #[\SensitiveParameter] array $payload, int $domainId): array
    {
        if (in_array($action, ['contacts','contact_profile'], true)) { $this->contactsAllowed($name, $domainId); }
        if ($action === 'contacts') { $payload['profile_operation'] = ManagementProfiles::select($this->binding, $name, $payload, $key, $domainId); } return $payload;
    }
    private function contactsAllowed(string $name, ?int $domainId): void
    {
        $d = Domain::whereKey($domainId)->where('account_fingerprint', $this->binding->fingerprint)->where('domain', $name)->sole();
        $tld = Tld::where('server_id', $this->binding->serverId)->where('tld', $d->tld)->sole();
        if ($tld->account_fingerprint !== $this->binding->fingerprint || !RegistryInputs::supported('namesilo', $d->tld, $tld->registry_fields)) {
            throw new \DomainException('Registry contact schema unsupported.');
        }
    }
    protected function origin(RequestContext $context): string
    { return ($this->binding->environment === \Paymenter\Extensions\Servers\Domains\Data\Environment::Live ? Environment::Live : Environment::Sandbox)->origin().($context->origin === RequestOrigin::Interactive ? '/api' : '/apibatch'); }
    protected function readCommands(): array { return ['getDomainInfo','contactList']; }
    protected function validateRead(string $command, #[\SensitiveParameter] array $p): void
    {
        if ($command === 'getDomainInfo' && array_keys($p) === ['domain'] && is_string($p['domain']) && CanonicalName::normalize($p['domain']) === $p['domain']) { return; }
        if ($command === 'contactList' && array_keys($p) === ['contact_id'] && self::id($p['contact_id'])) { return; }
        throw new \DomainException('Invalid named management read.');
    }
    protected function writeCommand(string $action): string
    {
        return match ($action) {
            'nameservers' => 'changeNameServers', 'contacts' => 'contactDomainAssociate', 'contact_profile' => 'contactAdd',
            'lock' => 'domainLock', 'privacy_enable' => 'addPrivacy', 'privacy_disable' => 'removePrivacy',
            'auto_renew_off' => 'removeAutoRenewal', 'auth_code_email' => 'retrieveAuthCode',
            default => throw new \DomainException('Unsupported NameSilo management action.'),
        };
    }
    protected function requestCommand(ManagementRequest $r): string
    { return $r->action === 'lock' && ($r->payload()['locked'] ?? null) === false ? 'domainUnlock' : $this->writeCommand($r->action); }
    private static function id(#[\SensitiveParameter] mixed $id): bool
    { return is_string($id) && preg_match('/^[1-9][0-9]{0,19}$/D', $id) === 1; }
    private static function reply(#[\SensitiveParameter] string $body, string $command): array
    {
        try { $v = json_decode($body, true, 24, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (\Throwable) { throw new \DomainException('Management reply unavailable.'); }
        $p = $v['reply'] ?? null;
        if (($v['request']['operation'] ?? null) !== $command || !is_array($p) || !is_string($p['detail'] ?? null) || !in_array($p['code'] ?? null, [300,'300'], true)) {
            throw new \DomainException('Management outcome unknown.');
        }
        // A baseline management response cannot carry purchase/charge evidence.
        foreach (['order_amount','order_number','order_id'] as $key) { if (array_key_exists($key, $p)) { throw new \DomainException('Contradictory management reply.'); } }
        return $p;
    }
    private function owned(ManagementRequest $r, RequestContext $c): array
    {
        if ($r->providerId !== null || CanonicalName::normalize($r->name) !== $r->name) { throw new \DomainException('Domain identity unavailable.'); }
        $request = RegistrarRequest::domainInfo($r->name);
        $info = (new ResponseParser)->parse($this->read($r, $c, 'getDomainInfo', $request->parameters()), $request, '');
        if ($info->outcome !== Outcome::Success || $info->value('status') !== 'Active') { throw new \DomainException('Owned active domain required.'); }
        return ['locked' => self::flag($info->value('locked')), 'privacy' => self::flag($info->value('private')),
            'auto_renew' => self::flag($info->value('auto_renew')), 'nameservers' => $info->value('nameservers'), 'contact_ids' => $info->value('contact_ids')];
    }
    private static function flag(mixed $v): ?bool { return match ($v) { 'Yes' => true, 'No' => false, default => null }; }
    private function profile(ManagementRequest $r, RequestContext $c, #[\SensitiveParameter] string $id): void
    {
        $reply = self::reply($this->read($r, $c, 'contactList', ['contact_id' => $id]), 'contactList');
        $rows = $reply['contact'] ?? null; if (is_array($rows) && !array_is_list($rows)) { $rows = [$rows]; }
        $row = is_array($rows) && count($rows) === 1 ? $rows[0] : null;
        if (!is_array($row) || (string) ($row['contact_id'] ?? '') !== $id || !in_array($row['default_profile'] ?? null, [0,'0'], true)) { throw new \DomainException('Non-default profile proof required.'); }
        $map = ['first_name','last_name','company','address','address2','city','state','zip','country','email','phone'];
        foreach (array_combine($map, array_values($r->payload()['contact'])) as $field => $wanted) {
            if (($row[$field] ?? null) !== $wanted) { throw new \DomainException('Contact readback mismatch.'); }
        }
    }
    public function prepare(ManagementRequest $r, RequestContext $c): array
    {
        $this->writeCommand($r->action);
        if (in_array($r->action, ['contacts','contact_profile'], true)) { $this->contactsAllowed($r->name, $r->domainId); }
        $this->owned($r, $c);
        if ($r->action === 'contacts') {
            $profile = ManagementProfiles::resolve($r, $c->now()); $this->profile($r, $c, $profile->details['provider_id']);
        }
        return $r->payload();
    }
    protected function apply(ManagementRequest $r, RequestContext $c): ManagementResult
    {
        $p = $r->payload(); $args = ['domain' => $r->name];
        if ($r->action === 'nameservers') { foreach ($p['nameservers'] as $i => $ns) { $args['ns'.($i + 1)] = $ns; } }
        elseif ($r->action === 'contact_profile') { $args = RegistrationProfiles::parameters($p['contact']); }
        elseif ($r->action === 'contacts') {
            $profile = ManagementProfiles::resolve($r, $c->now());
            $roles = ['registrant'=>'registrant','admin'=>'administrative','tech'=>'technical','auxbilling'=>'billing'];
            foreach ($p['role'] === 'all' ? ContactDetails::ROLES : [$p['role']] as $role) { $args[$roles[$role]] = $profile->details['provider_id']; }
        }
        $cmd = $this->requestCommand($r);
        $body = $this->write($r, $c, $cmd, $args);
        try { $ack = self::reply($body, $cmd); } catch (\Throwable) { return new ManagementResult; }
        // Unestablished/non-300 outcomes stay unknown; independent readback may later confirm state.
        if ($r->action === 'auth_code_email') { $this->acknowledge($r, $c, ['email_accepted' => true]); }
        if ($r->action === 'contact_profile') {
            $id = $ack['contact_id'] ?? null; if (is_int($id)) { $id = (string) $id; }
            if (!self::id($id)) { return new ManagementResult; }
            $this->acknowledge($r, $c, ['profile_id' => $id]);
            $r = new ManagementRequest($r->binding, $r->key, $r->name, $r->action, $p, $r->providerId, profileId: $id, domainId: $r->domainId);
        }
        return $this->observe($r, $c);
    }
    public function observe(ManagementRequest $r, RequestContext $c): ManagementResult
    {
        $facts = $this->owned($r, $c); $p = $r->payload(); $ok = false;
        switch ($r->action) {
            case 'nameservers':
                $rows = $facts['nameservers']; if (!is_array($rows) || !array_is_list($rows)) { break; }
                $actual = ManagementPayload::nameservers(array_map(fn ($row) => $row['nameserver'] ?? null, $rows)); $desired = $p['nameservers'];
                sort($actual); sort($desired); $ok = $actual === $desired; $facts = ['nameservers' => $actual]; break;
            case 'lock': $ok = $facts['locked'] === $p['locked']; $facts = ['locked' => $facts['locked']]; break;
            case 'privacy_enable': case 'privacy_disable':
                $ok = $facts['privacy'] === ($r->action === 'privacy_enable'); $facts = ['privacy' => ['enabled' => $facts['privacy']]]; break;
            case 'auto_renew_off': $ok = $facts['auto_renew'] === false; $facts = ['auto_renew' => $facts['auto_renew']]; break;
            case 'auth_code_email': return new ManagementResult(true); // Engine requires original live email acknowledgement.
            case 'contact_profile':
                if (!self::id($r->profileId)) { break; }
                $this->profile($r, $c, $r->profileId);
                $facts = ['local_profile_id' => ManagementProfiles::retain($r, $p['contact'])]; $ok = true; break;
            case 'contacts':
                $profile = ManagementProfiles::resolve($r, $c->now()); $id = $profile->details['provider_id']; $this->profile($r, $c, $id);
                $roles = ['registrant'=>'registrant','admin'=>'administrative','tech'=>'technical','auxbilling'=>'billing']; $ok = true;
                foreach ($p['role'] === 'all' ? ContactDetails::ROLES : [$p['role']] as $role) { if (($facts['contact_ids'][$roles[$role]] ?? null) !== $id) { $ok = false; } }
                $facts = ['contact_ids' => $facts['contact_ids'], 'association_confirmed' => $ok]
                    + ($ok ? ['contacts' => array_fill_keys($p['role'] === 'all' ? ContactDetails::ROLES : [$p['role']], $p['contact'])] : []); break;
            default: throw new \DomainException('Unsupported management observation.');
        }
        return new ManagementResult($ok, false, $facts);
    }
}
