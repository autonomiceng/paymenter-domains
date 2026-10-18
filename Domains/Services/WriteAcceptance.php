<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, Capability, Query, Support};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Trusted provider acceptance, separate from catalogue, identity and live spending authority.
 * Default refuses. The local profileProofCheck must inspect durable fresh proof bound to the
 * exact frozen owner/account/payload digest, with NO provider I/O (it runs under financial locks).
 * Slices21/30 perform contactAdd + non-default readback outside locks and persist that proof.
 * Account inventory alone is never sufficient.
 */
final readonly class WriteAcceptance
{
    public function __construct(private AccountBinding $binding, private bool $usdBalance = false,
        private bool $batchRoute = false, private int $intervalSeconds = 0,
        private RegistrationProfiles|\Closure|null $profileProofCheck = null) {}
    public function usesProfiles(RegistrationProfiles $profiles): bool { return $this->profileProofCheck === $profiles; }
    public function requireCurrent(AccountBinding $binding, Query $query, Operation $op, bool $lock = false, bool $profile = true): Capability
    {
        $this->binding->requireMatch($binding);
        if (!$this->usdBalance || !$this->batchRoute || $this->intervalSeconds < 25 || $this->intervalSeconds > 86400) {
            throw new WriteHeld('write_acceptance_required');
        }
        $accepted = CatalogueAcceptances::load($binding, $lock);
        $parts = CanonicalName::parse($query->name, array_keys($accepted->endings));
        $row = $accepted->endings[$parts['tld']];
        if (!in_array($query->years, $row['register'], true) || $row['renew'] !== [1] || !$row['renewal_safe'] || !$row['registry']) {
            throw new WriteHeld('write_acceptance_required');
        }
        if ($profile && $binding->registrarKey === 'namesilo' && !($this->profileProofCheck instanceof RegistrationProfiles
            ? $this->profileProofCheck->hasProof($op) : ($this->profileProofCheck && ($this->profileProofCheck)($op) === true))) {
            throw new WriteHeld('contact_profile_proof_required');
        }
        return new Capability(Support::Supported, $row['register']);
    }
    /** Reserve a local account slot without waiting or holding a database lock over I/O. */
    public function reserve(AccountBinding $binding): void
    {
        $this->binding->requireMatch($binding);
        if (!$this->usdBalance || !$this->batchRoute || $this->intervalSeconds < 25 || $this->intervalSeconds > 86400) { throw new WriteHeld('write_acceptance_required'); }
        if (!Cache::add('domains-write-slot:'.$binding->fingerprint, true, $this->intervalSeconds)) {
            throw new WriteHeld('write_rate_limited');
        }
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Write acceptance is server-only.'); }
}
