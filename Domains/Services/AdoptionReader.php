<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{Review, StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Models\Adoption;

/** Preview-only consumer of retained evidence. No holding, invoice or operation mutation. */
final class AdoptionReader
{
    public function __construct(private readonly AccountBindings $bindings, private readonly AdoptionMapping $mapping,
        private readonly Clock $clock) {}
    public function preview(Target $target, StaffContext $staff): Review
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Adoption preview reads require no outer transaction.'); }
        if (!Staff::allows(User::find($staff->actorId), Staff::ADOPT_PREVIEW)) { return new Review('permission_denied'); }
        try { $before = $this->mapping->capture($target, $staff); }
        catch (\Throwable $e) { return self::refusal($e, 'mapping_unconfirmed'); }
        try { $reader = $this->bindings->adoptionReader($before['binding'], $this->clock); }
        catch (\Throwable $e) { return self::refusal($e, 'account_unconfirmed'); }
        $doNotRenew = $staff->doNotRenew || $before['existing_do_not_renew'];
        $captureDigest = AdoptionMapping::stamp($this->comparable($before));
        $intentDigest = AdoptionMapping::stamp(['capture_digest' => $captureDigest, 'actor_id' => $staff->actorId, 'reason' => $staff->reason,
            'requested_do_not_renew' => $staff->doNotRenew, 'do_not_renew' => $doNotRenew, 'environment' => $target->environment->value]);
        $observed = $this->clock->now();
        try { $read = $reader->read($target->name, $before['suffix']); }
        catch (\Throwable $e) { return self::refusal($e, 'read_failed'); }
        try {
            if (!Staff::allows(User::find($staff->actorId), Staff::ADOPT_PREVIEW)) { return new Review('changed'); }
            $after = $this->mapping->capture($target, $staff);
            if ($captureDigest !== AdoptionMapping::stamp($this->comparable($after))) { return new Review('changed'); }
            // Reapply current site/credential/environment guard after the unlocked read.
            $this->bindings->adoptionReader($before['binding'], $this->clock);
        } catch (\Throwable $e) { self::diagnostic($e); return new Review('changed'); }
        $facts = $read->facts(); $code = $facts['refusal'] ?? 'ready';
        if (!$before['binding']->matches($read->binding) || ($code === 'ready' && $facts['name'] !== $target->name)) { $code = 'identity_unconfirmed'; }
        if ($code === 'ready' && $facts['auto_renew'] !== false) { $code = $facts['auto_renew'] === true ? 'auto_renew_on' : 'auto_renew_unknown'; }
        if ($code === 'ready' && ($facts['ordinary'] !== true || $facts['registrar_hold'] !== false || $facts['redemption'] !== false)) { $code = 'state_unconfirmed'; }
        if ($code === 'ready' && $facts['provider_id'] !== null && \Paymenter\Extensions\Servers\Domains\Models\Domain::where('server_id', $target->serverId)
            ->where('provider_domain_id', $facts['provider_id'])->where('domain', '!=', $target->name)->exists()) { $code = 'identity_unconfirmed'; }
        if ($code === 'ready' && $before['expected_provider_id'] !== null && (string) $before['expected_provider_id'] !== $facts['provider_id']) { $code = 'identity_unconfirmed'; }
        $expiry = $facts['expiry'] ?? null;
        if ($code === 'ready' && $expiry->precision === \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Local) { $code = 'expiry_unconfirmed'; }
        if ($code === 'ready' && new \DateTimeImmutable($expiry->value) <= $this->clock->now()) { $code = 'expired'; }
        if ($code === 'ready' && new \DateTimeImmutable($expiry->value) > $observed->modify('+10 years')) { $code = 'state_unconfirmed'; }
        if ($code === 'ready' && $expiry && $before['expected_expiry'] !== null && substr($expiry->value, 0, 10) !== $before['expected_expiry']) { $code = 'expiry_disagrees'; }
        if ($this->clock->now() >= $observed->modify('+90 seconds')) { $code = 'read_failed'; }
        $calendar = $expiry ? BillingCalendar::dates(new \DateTimeImmutable($expiry->value), $before['calendar_timing']['issue'], $before['calendar_timing']['due']) : null;
        $environment = $before['binding']->environment->value;
        $renewalEligibility = AdoptionRenewalEligibility::forRegistrar($before['binding']->registrarKey);
        $evidence = ['version' => 1, 'canonical_name' => $target->name, 'native_identity' => $before['native_identity'],
            'registrar_key' => $before['binding']->registrarKey, 'environment' => $environment, 'account_proof_digest' => $before['binding']->fingerprint,
            'provider_domain_id' => $code === 'ready' ? $facts['provider_id'] : null,
            'source_expiry' => $before['expected_expiry'], 'expiry' => $expiry ? ['value' => $expiry->value, 'precision' => $expiry->precision->value, 'timezone' => $expiry->timezone, 'source' => $expiry->source] : null,
            'auto_renew' => $facts['auto_renew'] ?? null, 'ordinary' => $facts['ordinary'] ?? null, 'hold' => $facts['registrar_hold'] ?? null,
            'redemption' => $facts['redemption'] ?? null, 'settings' => $facts['settings'] ?? null, 'read_started_at' => $observed->format(DATE_ATOM), 'read_ended_at' => $this->clock->now()->format(DATE_ATOM),
            'capture_digest' => $captureDigest, 'intent_digest' => $intentDigest,
            'permission_snapshot' => [Staff::VIEW => true, Staff::ADOPT_PREVIEW => true],
            'command_set_version' => $facts['command_set_version'] ?? null, 'credential_revision' => $before['stamps']['settings'], 'revisions' => $before['stamps'],
            'retail' => $before['retail'], 'calendar' => $calendar, 'renewal_eligibility' => $renewalEligibility, 'existing_do_not_renew' => $before['existing_do_not_renew'], 'prior_generation' => $before['prior_generation'], 'prior_revision' => $before['prior_revision'],
            'resulting_generation' => $before['prior_generation'] + 1, 'resulting_revision' => $before['prior_revision'] + 1,
            'source_conversion_row_id' => null, 'history_link_ids' => $before['history_link_ids'],
            'actor_id' => $staff->actorId, 'reason' => $staff->reason, 'requested_do_not_renew' => $staff->doNotRenew, 'do_not_renew' => $doNotRenew, 'refusal' => $code === 'ready' ? null : $code];
        // No portable authority is produced, including by live previews. A later commit must independently lock and revalidate.
        $row = DB::transaction(fn () => Adoption::create(['request_key' => bin2hex(random_bytes(24)), 'request_digest' => AdoptionMapping::stamp($evidence),
            'domain_id' => $before['domain_id'], 'service_id' => $target->serviceId, 'user_id' => $target->customerId, 'server_id' => $target->serverId,
            'registrar_key' => $before['binding']->registrarKey, 'environment' => $environment, 'account_fingerprint' => $before['binding']->fingerprint,
            'state' => $code === 'ready' ? 'preview' : 'refused', 'origin' => 'staff', 'evidence' => $evidence, 'actor_id' => $staff->actorId,
            'reason' => $staff->reason, 'do_not_renew' => $doNotRenew, 'observed_at' => $observed, 'valid_until' => $observed->modify('+120 seconds')]));
        return new Review($code, ['name' => $target->name, 'customer_id' => $target->customerId, 'service_id' => $target->serviceId,
            'environment' => $environment, 'test_only' => $environment === 'test', 'expiry' => $expiry?->value,
            'renewal_due' => $renewalEligibility['eligible'] ? ($calendar['due'] ?? null) : null, 'source_expiry' => $before['expected_expiry'],
            'price_notice' => $before['retail']['staff_alert'], 'existing_do_not_renew' => $before['existing_do_not_renew'],
            'annual_charge' => $renewalEligibility['eligible'] ? $before['retail']['annual_gross'] : null, 'renewal_eligibility' => $renewalEligibility, 'currency' => 'USD', 'history' => $before['history_review'], 'requested_do_not_renew' => $staff->doNotRenew, 'do_not_renew' => $doNotRenew], $row->id);
    }
    private function comparable(array $capture): array { unset($capture['binding']); return $capture; }
    private static function refusal(\Throwable $error, string $fallback): Review
    {
        self::diagnostic($error);
        return new Review(array_key_exists($error->getMessage(), Review::LABELS) ? $error->getMessage() : $fallback);
    }
    private static function diagnostic(\Throwable $error): void
    {
        if (!$error instanceof \DomainException) {
            try { \Illuminate\Support\Facades\Log::warning('Domains adoption preview failed.', ['exception_class' => $error::class]); }
            catch (\Throwable) { /* Diagnostics must preserve the safe refusal. */ }
        }
    }
}
