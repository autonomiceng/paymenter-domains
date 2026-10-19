<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,RequestOrigin};
final class WireLimit implements \Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter
{
    public int $calls=0;public int $released=0;public ?\Closure $onAcquire=null;
    public function __construct(public int $allowed) {}
    public function acquire(AccountBinding $b,RequestOrigin $o): string {if(++$this->calls>$this->allowed){throw new \Paymenter\Extensions\Servers\Domains\Services\ReadDeferred;}($this->onAcquire??static fn()=>null)();return 'synthetic';}
    public function release(AccountBinding $b,RequestOrigin $o,string $t): void {$this->released++;}
}
