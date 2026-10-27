<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Paymenter\Extensions\Servers\Domains\Services\WatchWork;
final class RunPublicCheck implements ShouldQueue,ShouldBeUnique
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $uniqueFor=120; public int $timeout=60; public int $tries=1; public bool $failOnTimeout=true;
    public function __construct(public array $target) {}
    public function uniqueId(): string { return 'domains-public|'.(isset($this->target['watch_id'])?'watch:'.$this->target['watch_id']:'managed:'.$this->target['domain_id']).'|'.$this->target['kind']; }
    public function handle(WatchWork $work): void { $work->observe($this->target); }
}
