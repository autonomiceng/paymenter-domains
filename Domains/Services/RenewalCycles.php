<?php
// Enom d1392a8 DomainBilling::complimentary/retireUnpaidRenewals; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Explicit internal financial transitions. Native UI/scheduling are separate consumers. */
final readonly class RenewalCycles
{
    public function __construct(private AccountBindings $bindings, private Clock $clock) {}
    public static function key(Domain $d): string { return 'renew:'.$d->id.':g'.$d->authority_generation.':'.$d->provider_expires_at->format('Y-m-d'); }
    public static function next(array $l): string
    {
        $key = self::key($l['domain']); $cycle = $l['operations']->filter(fn ($op) => $op->action === 'renew'
            && $op->authority_generation === $l['domain']->authority_generation && $op->base_expires_at == $l['domain']->provider_expires_at);
        foreach ($cycle as $op) {
            if (RenewalReplacements::reviewed($op,$l['domain'])) { RenewalReplacements::requireSettled($op,$l); continue; }
            if (!OperationTerminalProof::safe($op, $l['domain']) || $op->safe_code !== 'renewal_cancelled_unpaid'
                || (($l['invoices']->firstWhere('id', $op->invoice_id)?->status !== 'cancelled'
                    || $l['transactions']->contains('invoice_id', $op->invoice_id)) && !RefundPayment::retiredSettled($op, $l))) {
                throw new \DomainException('A renewal obligation already owns this cycle.');
            }
        }
        return $cycle->isEmpty() ? $key : $key.':replacement:'.$cycle->count();
    }
    public function retireUnpaid(int $id): Operation
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        return DB::transaction(function () use ($seed, $id): Operation {
            $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
            $invoice = $l['invoices']->firstWhere('id', $op?->invoice_id); $item = $l['items']->firstWhere('id', $op?->invoice_item_id);
            if (!$op || $op->action !== 'renew' || $op->funding_basis !== 'paid_invoice' || !$invoice || !$item
                || $op->service_id !== $l['domain']->service_id || $op->authority_generation !== $l['domain']->authority_generation
                || (int) $invoice->user_id !== (int) $l['domain']->user_id || $invoice->currency_code !== 'USD'
                || (int) $item->invoice_id !== (int) $invoice->id
                || !in_array($invoice->status, ['pending', 'cancelled'], true) || $l['transactions']->contains('invoice_id', $invoice->id)
                || $l['items']->where('invoice_id', $invoice->id)->count() !== 1 || $item->reference_type !== null || $item->reference_id !== null
                || PricingRule::minor($item->price) !== PricingRule::minor($op->amount) || (string) $item->quantity !== '1'
                || !in_array($op->state, ['awaiting_payment', 'queued', 'held'], true) || !self::virgin($op)) { throw new \DomainException('Unpaid terminal proof unavailable.'); }
            $invoice->update(['status' => 'cancelled']);
            $op->update(['state' => 'rejected', 'safe_code' => 'renewal_cancelled_unpaid', 'completed_at' => $this->clock->now(),
                'attempt' => ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent',
                    'terminal' => ['operation_key' => $op->operation_key, 'generation' => $op->authority_generation, 'invoice_id' => $invoice->id, 'transactions' => []]]]);
            return $op;
        });
    }
    public function complimentary(int $domainId, User $actor, int $years, Money $cap, string $reason): Operation
    {
        OperationClaims::outside(); $seed = Domain::findOrFail($domainId);
        return DB::transaction(function () use ($seed, $actor, $years, $cap, $reason): Operation {
            $l = HoldingLocks::take($seed, (int) $actor->id); $staff = $l['staff'];
            if (!$staff || !$staff->hasPermission('admin.domains.manage') || !$staff->hasPermission('admin.domains.fund')
                || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $reason) || $cap->currency !== 'USD' || WriteMoney::compare($cap->amount, '0.00') <= 0) {
                throw new \DomainException('Audited complimentary funding required.');
            }
            $d = $l['domain']; $s = $l['service']; $binding = CatalogueAcceptances::binding($l['account']);
            $this->bindings->requireProcessingLocked($l['server'], $l['account']);
            if ($years < 1 || $years > 10 || $d->provider_expires_at?->addYearsNoOverflow($years) > $this->clock->now()->modify('+10 years')
                || $s->status !== 'active' || RenewalStop::blocksExisting($l) || ($d->renewal_policy['automatic_spend_paused'] ?? false)) { throw new \DomainException('Complimentary lifecycle unavailable.'); }
            $key = self::key($d); $existing = $l['operations']->filter(fn ($op) => $op->operation_key === $key || str_starts_with($op->operation_key, $key.':replacement:'))->last();
            if ($existing && $existing->funding_basis === 'complimentary') {
                if ((int) $existing->term !== $years || ($existing->policy['renewal']['cost_cap'] ?? null) !== ['amount' => $cap->amount, 'currency' => 'USD']) { throw new \DomainException('Complimentary term and cap are fixed.'); }
                return $existing;
            }
            if(RenewalStop::stopped($l)){throw new \DomainException('Future renewals are stopped for this domain.');}
            $key = self::next($l); $anchor = $l['operations']->firstWhere('operation_key', $d->renewal_policy['billing_anchor']['operation_key'] ?? null);
            if (!$anchor || !RenewalAuthority::completed($anchor, $d, $binding)
                || $l['operations']->contains(fn ($op) => !RenewalAuthority::resolved($op, $d, $binding))) { throw new \DomainException('Prior renewal outcome unavailable.'); }
            RenewalAuthority::requireCostAnchor($anchor);
            $authorization = ['actor' => (int) $staff->id, 'reason' => $reason, 'generation' => $d->authority_generation,
                'expiry' => $d->provider_expires_at->format('Y-m-d'), 'years' => $years, 'cap' => ['amount' => $cap->amount, 'currency' => 'USD'], 'at' => $this->clock->now()->format(DATE_ATOM)];
            $d->update(['renewal_policy' => [...$d->renewal_policy, 'complimentary' => $authorization]]);
            return Operation::create(['domain_id' => $d->id, 'service_id' => $s->id, 'server_id' => $d->server_id, 'registrar_key' => $d->registrar_key,
                'account_fingerprint' => $d->account_fingerprint, 'authority_generation' => $d->authority_generation, 'operation_key' => $key,
                'action' => 'renew', 'term' => $years, 'funding_basis' => 'complimentary', 'state' => 'queued', 'amount' => '0.00', 'currency' => 'USD',
                'authorized_by' => $staff->id, 'funding_reason' => $reason, 'base_expires_at' => $d->provider_expires_at,
                'expected_expires_at' => $d->provider_expires_at->addYearsNoOverflow($years), 'payload' => [],
                'policy' => ['complimentary' => $authorization, 'renewal' => ['confirmed_revision' => $d->confirmed_revision,
                    'provider_domain_id' => $d->provider_domain_id, 'term' => $years, 'product_id' => (int) $s->product_id, 'annual_plan_id' => (int) $s->plan_id,
                    'user_id' => (int) $s->user_id, 'order_id' => (int) $s->order_id, 'cost_cap' => $authorization['cap']]]]);
        });
    }
    public static function virgin(Operation $op): bool
    {
        return ($op->attempt ?? []) === [] && ($op->cost ?? []) === [] && FinancialAdjustments::unattemptedFunding($op)
            && $op->provider_order_id === null && $op->confirmed_expires_at === null && $op->started_at === null && $op->request_fingerprint === null
            && !array_key_exists('dispatch_attempted_at', $op->policy ?? []);
    }
}
