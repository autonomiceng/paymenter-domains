<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RenewalCode};
/** Internal account policy, default closed. No relation to seller/new-sale renew_enabled flags. */
final readonly class RenewalAcceptance
{
    private WriteAcceptance $account;
    public function __construct(private AccountBinding $binding, private bool $usdBalance = false,
        private bool $batchRoute = false, private int $intervalSeconds = 0, public bool $grace = false, private ?\Closure $localCheck = null)
    { $this->account = new WriteAcceptance($binding, $usdBalance, $batchRoute, $intervalSeconds); }
    public function requireCurrent(AccountBinding $binding): void
    {
        $this->binding->requireMatch($binding);
        if (($this->localCheck && ($this->localCheck)($binding)!==true) || !$this->usdBalance || !$this->batchRoute || $this->intervalSeconds < 25 || $this->intervalSeconds > 86400) {
            throw new RenewalRefused(RenewalCode::Acceptance);
        }
    }
    public function reserve(AccountBinding $binding): void
    {
        $this->requireCurrent($binding);
        try { $this->account->reserve($binding); }
        catch (WriteHeld) { throw new RenewalRefused(RenewalCode::Rate); }
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Renewal acceptance is server-only.'); }
}
