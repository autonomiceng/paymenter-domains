<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\RequestContext;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** One bounded read result, shared by background work, staff feedback and customer notices. */
final class RegistrarCheck
{
    public ?RequestContext $context = null;
    public ?string $deferred = null;
    public function __construct(private readonly Clock $clock) {}
    public function finish(Operation $result): Operation
    {
        return DB::transaction(function () use ($result): Operation {
            Domain::whereKey($result->domain_id)->lockForUpdate()->firstOrFail();
            $op = Operation::whereKey($result->id)->lockForUpdate()->firstOrFail();
            $now = $this->clock->now()->getTimestamp();
            $outcome = $this->deferred !== null ? 'deferred' : (($this->context?->completedReads() ?? 0) > 0 ? 'checked' : 'unavailable');
            $next = null;
            $watch = TransferPolling::applies($op);
            $stopped = $op->action === 'renew' ? ($op->policy['renewal_polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT
                : ($op->policy['reconciliation']['polls'] ?? 0) >= OperationReconciliation::POLL_LIMIT && (!$watch || !TransferPolling::expected($op));
            if ($watch && ($op->policy['reconciliation']['final_check'] ?? null) === 'done') { $stopped = true; }
            $transferNext = $op->policy['reconciliation']['next_at'] ?? null;
            if (Schema::hasTable('domains_runtime_operations') && !$stopped && $op->state !== 'succeeded' && ($outcome === 'deferred' || $outcome === 'checked')) {
                if ($watch) { $next = max($now + 60, (int) $transferNext); }
                else {
                    $next = max($now + 300, (int) DB::table('domains_runtime_operations')->where('operation_id', $op->id)->value('due_at'));
                    DB::table('domains_runtime_operations')->updateOrInsert(['operation_id' => $op->id], ['due_at' => $next]);
                }
            }
            $op->update(['policy' => [...($op->policy ?? []), 'status_check' => ['outcome' => $outcome, 'at' => $now, 'next_at' => $next, 'reason' => $this->deferred]]]);
            return $op;
        });
    }
    /** Called under the existing review locks; a resume resets scheduling, never the sealed attempt. */
    public static function resumed(Operation $op, int $now): void
    {
        if (Schema::hasTable('domains_runtime_operations')) {
            DB::table('domains_runtime_operations')->updateOrInsert(['operation_id' => $op->id], ['due_at' => $now]);
        }
        $op->update(['policy' => [...$op->policy, 'status_check' => ['outcome' => 'scheduled', 'at' => $now, 'next_at' => $now, 'reason' => null]]]);
    }
    /** Plain words used everywhere this result appears. No provider payload enters the policy or UI. */
    public static function words(?array $check, bool $completed = false): array
    {
        $next = isset($check['next_at']) ? gmdate('M j, Y H:i', (int) $check['next_at']).' UTC' : null;
        return match ($check['outcome'] ?? null) {
            'checked' => ['title' => 'Registrar checked', 'body' => $completed ? 'The request is complete. Open the domain for its details.'
                : 'The result is still unconfirmed. '.($next ? 'The next check is at '.$next.'.' : 'Contact support to review the original order.')],
            'deferred' => ['title' => 'Check delayed', 'body' => (($check['reason'] ?? null) === 'scheduled' ? 'The next status check is not due yet.' : (($check['reason'] ?? null) === 'send_guard' ? 'Our sending settings paused this check.' : 'Our check limit was reached.'))
                .($next ? ' The next check is at '.$next.'.' : ' Contact support to restart checks.')],
            'scheduled' => ['title' => 'Status checks restarted', 'body' => 'The system will check again on its next turn.'],
            default => ['title' => 'Cannot check registrar', 'body' => 'We could not get an answer from the registrar. Support can check the original order.'],
        };
    }
    public static function for(Operation $op): array { return self::words($op->policy['status_check'] ?? null, $op->state === 'succeeded'); }
}
