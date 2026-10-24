<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Paymenter\Extensions\Servers\Domains\Services\PublicSuffixList;
/** Public-data DNS and HTTP run separately from the account runtime turn. */
final class UpdatePublicSuffixList implements ShouldQueue,ShouldBeUnique
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $uniqueFor=900;
    public int $timeout=120;
    public int $tries=1;
    public bool $failOnTimeout=true;
    public function uniqueId(): string {return 'domains-public-suffix-list';}
    public function handle(PublicSuffixList $lists): void {$lists->tick();}
}
