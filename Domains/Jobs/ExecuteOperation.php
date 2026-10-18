<?php
// Adapted from Enom 444f31c Jobs/ExecuteOperation.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Paymenter\Extensions\Servers\Domains\Services\DomainOperations;

final class ExecuteOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public const REQUEST_SECONDS = 25;
    public const REQUEST_LIMIT = 10;
    public const EXECUTION_SECONDS = self::REQUEST_LIMIT * self::REQUEST_SECONDS + 50;
    public const CLEANUP_SECONDS = 60;
    public const LEASE_SECONDS = self::EXECUTION_SECONDS + self::CLEANUP_SECONDS;
    public int $tries = 1;
    public int $timeout = self::EXECUTION_SECONDS;
    public bool $failOnTimeout = true;
    public function __construct(public readonly int $operationId) {}
    public function handle(DomainOperations $operations): void { $operations->execute($this->operationId); }
}
