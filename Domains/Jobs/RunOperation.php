<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldQueue,ShouldBeUnique};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
final class RunOperation implements ShouldQueue,ShouldBeUnique
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $uniqueFor=900;
    public function uniqueId(): string {return 'domains-operation-'.$this->operationId;}
    public int $tries=1;
    public int $timeout=ExecuteOperation::EXECUTION_SECONDS;
    public bool $failOnTimeout=true;
    public function __construct(public readonly int $operationId) {}
    public function handle(\Paymenter\Extensions\Servers\Domains\Services\RuntimeWork $work): void {$work->operation($this->operationId);}
}
