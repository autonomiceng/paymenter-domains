<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, ReadDeferred};

/** Holds one whole-feed local lease; existing transport daily/spend admission still runs per wire. */
final class FeedReadBudget implements ReadLimiter
{
    private int $left;
    private bool $active = false;
    private bool $finished = false;
    private readonly string $token;
    public function __construct(private readonly AccountReadBudget $minute, private readonly AccountBinding $binding,
        private readonly RequestOrigin $origin, int $calls, private readonly ?ReadLimiter $other = null)
    {
        $this->token = $minute->acquire($binding, $origin); $this->left = $calls;
    }
    public function acquire(AccountBinding $binding, RequestOrigin $origin): string
    {
        $this->binding->requireMatch($binding);
        if ($origin !== $this->origin || $this->finished || $this->active || $this->left < 1) { throw new ReadDeferred('Feed reservation exhausted.'); }
        $token = $this->other?->acquire($binding, $origin) ?? '';
        $this->active = true; $this->left--; return $token;
    }
    public function release(AccountBinding $binding, RequestOrigin $origin, string $token): void
    {
        $this->binding->requireMatch($binding);
        if ($origin !== $this->origin || !$this->active) { throw new \LogicException('Invalid feed release.'); }
        try { $this->other?->release($binding, $origin, $token); } finally { $this->active = false; }
    }
    public function finish(): void
    {
        if (!$this->finished) { $this->minute->release($this->binding, $this->origin, $this->token); $this->finished = true; }
    }
}
