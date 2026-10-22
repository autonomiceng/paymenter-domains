<?php
// Adapted from Enom 444f31c/b561b9f Services/DomainBilling.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Invoice, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

/** Native debts only. Native full-credit settlement only; no provider admission, grants, dispatch or notification delivery. */
final class DomainBilling
{
    public function __construct(private readonly AccountBindings $bindings, private readonly NativeDomainGuard $native, private readonly Clock $clock) {}

    public static function calendar(Domain $domain, int $issueDays = 21, int $dueDays = 7): array
    {
        if (!$domain->provider_expires_at || $issueDays <= $dueDays || $dueDays < 1 || $issueDays > 365) {
            throw new \DomainException('Confirmed expiry and valid billing calendar required.');
        }
        return ['expiry' => $domain->provider_expires_at->format('Y-m-d'),
            'issue' => $domain->provider_expires_at->subDays($issueDays)->format('Y-m-d'),
            'due' => $domain->provider_expires_at->subDays($dueDays)->format('Y-m-d')];
    }

    /** A null actor selects scheduled billing, skipped on native cancellation; manual billing requires the current owner. */
    public function invoice(int $domainId, ?User $actor = null, ?int $years = null): ?Invoice
    {
        OperationClaims::outside(); $seed = Domain::findOrFail($domainId);
        return DB::transaction(function () use ($seed, $actor, $years): ?Invoice {
            $l = HoldingLocks::take($seed); $d = $l['domain']; $s = $l['service'];
            if ($actor && (int) $actor->id !== (int) $l['user']->id) { throw new \DomainException('Domain renewal is unavailable.'); }
            // A native cancellation disables scheduled billing without touching retained debts.
            if ($actor === null && $l['cancel']->contains('service_id', $s->id)) { return null; }
            if (!$d->provider_expires_at) { throw new \DomainException('Confirmed expiry required.'); }
            $key = RenewalCycles::key($d);
            // Issued debt keeps its calendar, term and amount through later configuration changes.
            $existing = $l['operations']->filter(fn ($op) => $op->operation_key === $key || str_starts_with($op->operation_key, $key.':replacement:'))->last();
            if ($existing && $existing->safe_code !== 'renewal_cancelled_unpaid' && !RenewalReplacements::reviewed($existing,$d)) { return $this->existing($existing, $l, $years); }
            $key = RenewalCycles::next($l);
            self::requireAuthority($l,$this->bindings);
            $settings = $l['settings']->pluck('value', 'key');
            $calendar = self::calendarFor($l); $years ??= 1;
            if (!self::enabled($settings->get($actor ? 'domains_manual_renewals_enabled' : 'domains_billing_enabled', false))) {
                throw new \DomainException('Requested renewal billing gate is disabled.');
            }
            $today = $this->clock->now()->format('Y-m-d');
            if ($today >= $calendar['due'] || (!$actor && $today < $calendar['issue'])) { throw new \DomainException('Outside renewal invoice calendar.'); }
            if ($years < 1 || $years > 10 || $d->provider_expires_at->addYearsNoOverflow($years) > $this->clock->now()->modify('+10 years')) {
                throw new \DomainException('Renewal term exceeds the registry horizon.');
            }
            // Holding retail is independent of current seller, sales/renew flags and provider cost.
            $price = $this->native->project($l);
            $amount = PricingRule::total($price['annual_gross'], $years);
            $invoice = new Invoice(['user_id' => $d->user_id, 'currency_code' => 'USD', 'status' => Invoice::STATUS_PENDING, 'due_at' => $calendar['due']]);
            $invoice->send_create_email = false; $invoice->save();
            $item = $invoice->items()->create(['reference_type' => null, 'reference_id' => null, 'quantity' => 1, 'price' => $amount,
                'description' => InvoiceDescription::renewal($d->domain, $d->provider_expires_at, $years)]);
            $rate = $l['prices']->where('term', 1)->where('currency', 'USD')->where('account_fingerprint', $d->account_fingerprint)->first();
            $cap = $rate?->wholesale !== null ? ['amount' => PricingRule::decimal(PricingRule::units(PriceCatalogue::cost($rate->wholesale)) * $years, 4), 'currency' => 'USD'] : null;
            Operation::create(['domain_id' => $d->id, 'service_id' => $s->id, 'server_id' => $d->server_id,
                'registrar_key' => $d->registrar_key, 'account_fingerprint' => $d->account_fingerprint, 'authority_generation' => $d->authority_generation,
                'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id, 'operation_key' => $key, 'action' => 'renew', 'term' => $years,
                'funding_basis' => 'paid_invoice', 'state' => 'awaiting_payment', 'amount' => $amount, 'currency' => 'USD',
                'base_expires_at' => $calendar['expiry'], 'expected_expires_at' => $d->provider_expires_at->addYearsNoOverflow($years)->format('Y-m-d'),
                'policy' => ['calendar' => $calendar, 'price' => $price, 'renewal' => ['confirmed_revision' => $d->confirmed_revision,
                    'provider_domain_id' => $d->provider_domain_id, 'cost_cap' => $cap, 'term' => $years, 'product_id' => (int) $s->product_id, 'annual_plan_id' => (int) $s->plan_id,
                    'user_id' => (int) $s->user_id, 'order_id' => (int) $s->order_id]], 'payload' => []]);
            if ($price['staff_alert'] !== null || $price['retained']) {
                $d->update(['observation' => [...($d->observation ?? []), 'renewal_price_alert' => ['operation_key' => $key,
                    'code' => $price['staff_alert'] ?? 'unknown_margin', 'at' => $this->clock->now()->format(DATE_ATOM)]]]);
            }
            // Source parity: full native account credit only; never create an extension settlement row.
            $credit = $l['credit'];
            if (self::enabled($settings->get('credits_auto_use', false)) && self::enabled(config('settings.credits_auto_use', false))
                && $credit && PricingRule::minor($credit->amount) >= PricingRule::minor($amount)) {
                $credit->amount = PricingRule::decimal(PricingRule::minor($credit->amount) - PricingRule::minor($amount)); $credit->save();
                \App\Helpers\ExtensionHelper::addPayment($invoice, null, $amount, isCreditTransaction: true);
            }
            return $invoice->fresh();
        });
    }


    /** Validated saved calendar, shared by billing and its month-out notice. */
    public static function calendarFor(array $l):array
    {
        $settings=$l['settings']->pluck('value','key');
        return self::calendar($l['domain'],self::days($settings->get('domains_issue_days',21)),self::days($settings->get('domains_due_days',7)));
    }

    /** Read-only eligibility shared with scheduled-invoice notices; caller holds HoldingLocks. */
    public static function requireAuthority(array $l,AccountBindings $bindings):void
    {
        $d=$l['domain'];$s=$l['service'];
        $bindings->requireProcessingLocked($l['server'],$l['account']);
        if($d->renewal_policy['automatic_spend_paused']??false){throw new \DomainException('Renewal spending requires review.');}
        if ($s->status !== 'active' || !in_array($d->status, ['active', 'expired', 'grace'], true)
            || $l['cancel']->contains('service_id', $s->id) || $d->price_mode !== 'standard') { throw new \DomainException('Renewal billing lifecycle requires review.'); }
        $anchor = $d->renewal_policy['billing_anchor'] ?? [];
        $prior = $l['operations']->firstWhere('operation_key', $anchor['operation_key'] ?? null);
        if (($anchor['expiry'] ?? null) !== $d->provider_expires_at?->format('Y-m-d') || !$prior
            || !RenewalAuthority::completed($prior, $d, CatalogueAcceptances::binding($l['account']))
            || $l['operations']->contains(fn ($op) => $op->id !== $prior->id && !RenewalAuthority::resolved($op, $d, CatalogueAcceptances::binding($l['account'])))) {
            throw new \DomainException('Confirmed renewal anchor or prior outcome requires review.');
        }
        RenewalAuthority::requireCostAnchor($prior);
    }

    private function existing(Operation $op, array $l, ?int $years): Invoice
    {
        $invoice = $l['invoices']->firstWhere('id', $op->invoice_id); $item = $l['items']->firstWhere('id', $op->invoice_item_id); $d = $l['domain'];
        if (!$invoice || !$item || $l['items']->where('invoice_id', $op->invoice_id)->count() !== 1 || $op->action !== 'renew' || $op->funding_basis !== 'paid_invoice' || ($years !== null && (int) $op->term !== $years)
            || $op->service_id !== $d->service_id || $op->server_id !== $d->server_id || $op->registrar_key !== $d->registrar_key
            || $op->account_fingerprint !== $d->account_fingerprint || $op->authority_generation !== $d->authority_generation
            || $invoice->status === Invoice::STATUS_CANCELLED || (int) $invoice->user_id !== (int) $d->user_id || $invoice->currency_code !== 'USD'
            || (int) $item->invoice_id !== (int) $invoice->id || $item->reference_type !== null || $item->reference_id !== null
            || (string) $item->quantity !== '1' || PricingRule::minor($item->price) !== PricingRule::minor($op->amount)
            || PricingRule::minor($op->amount) <= 0 || $op->currency !== 'USD'
            || $op->base_expires_at?->format('Y-m-d') !== ($op->policy['calendar']['expiry'] ?? null)
            || $op->expected_expires_at?->format('Y-m-d') !== $op->base_expires_at?->addYearsNoOverflow((int) $op->term)->format('Y-m-d')
            || $invoice->due_at->format('Y-m-d') !== ($op->policy['calendar']['due'] ?? null)) { throw new \DomainException('Existing renewal obligation requires review.'); }
        return $invoice;
    }

    private static function days(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) { throw new \DomainException('Invalid renewal timing.'); } return (int) $value;
    }
    private static function enabled(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
