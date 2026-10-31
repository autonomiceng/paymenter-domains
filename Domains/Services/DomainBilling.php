<?php
// Adapted from Enom 444f31c/b561b9f Services/DomainBilling.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Invoice, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};

/** Native renewal debts and payment collection; no registrar admission, grants or dispatch. */
final class DomainBilling
{
    public function __construct(private readonly AccountBindings $bindings, private readonly NativeDomainGuard $native, private readonly Clock $clock) {}

    public static function calendar(Domain $domain, int $issueDays = 21, int $dueDays = 7): array
    {
        if (!$domain->provider_expires_at) { throw new RuntimeIneligible(RuntimeReason::MissingAnchor); }
        return BillingCalendar::dates($domain->provider_expires_at, $issueDays, $dueDays);
    }

    /** A null actor selects scheduled billing, skipped on native cancellation; manual billing requires the current owner. */
    public function invoice(int $domainId, ?User $actor = null, ?int $years = null): ?Invoice
    {
        OperationClaims::outside(); $seed = Domain::findOrFail($domainId);
        return DB::transaction(function () use ($seed, $actor, $years): ?Invoice {
            $l = HoldingLocks::take($seed); $d = $l['domain']; $s = $l['service'];
            if ($l['identity_source']['kind'] === 'adoption') { HoldingAnchor::resolve($l); }
            if ($actor && (int) $actor->id !== (int) $l['user']->id) { throw new \DomainException('Domain renewal is unavailable.'); }
            if (RenewalStop::adopted($l)) {
                if ($actor) { throw new \DomainException('Renewal is off for this adopted registration.'); }
                return null;
            }
            // A native cancellation disables scheduled billing without touching retained debts.
            if ($actor === null && RenewalStop::stopped($l)) { return null; }
            if (!$d->provider_expires_at) { throw new RuntimeIneligible(RuntimeReason::MissingAnchor); }
            if ($actor && self::expired($d, $this->clock->now())) { throw new RuntimeIneligible(RuntimeReason::OutsideCalendar); }
            $key = RenewalCycles::key($d);
            // Issued debt keeps its calendar, term and amount through later configuration changes.
            $existing = $l['operations']->filter(fn ($op) => $op->operation_key === $key || str_starts_with($op->operation_key, $key.':replacement:'))->last();
            if ($existing && $existing->safe_code !== 'renewal_cancelled_unpaid' && !RenewalReplacements::reviewed($existing,$d)) { return self::existingInvoice($existing, $l, $years); }
            $key = RenewalCycles::next($l);
            $years ??= 1; $calendar = $this->requireRequest($l, $actor !== null, $years);
            // Holding retail is independent of current seller, sales/renew flags and provider cost.
            $price = $this->native->project($l);
            $amount = PricingRule::total($price['annual_gross'], $years);
            $invoice = new Invoice(['user_id' => $d->user_id, 'currency_code' => 'USD', 'status' => Invoice::STATUS_PENDING, 'due_at' => $calendar['due']]);
            $invoice->send_create_email = false; $invoice->save();
            $item = $invoice->items()->create(['reference_type' => null, 'reference_id' => null, 'quantity' => 1, 'price' => $amount,
                'description' => InvoiceDescription::renewal($d->domain, $d->provider_expires_at, $years)]);
            $rate = $l['prices']->where('term', 1)->where('currency', 'USD')->where('account_fingerprint', $d->account_fingerprint)->first();
            $cap = $rate?->wholesale !== null ? ['amount' => PricingRule::decimal(PricingRule::units(PriceCatalogue::cost($rate->wholesale)) * $years, 4), 'currency' => 'USD'] : null;
            $operation = Operation::create(['domain_id' => $d->id, 'service_id' => $s->id, 'server_id' => $d->server_id,
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
            // Match native renewal credit policy; HoldingLocks already locked the invoice-currency credit row.
            $credit = $l['credit'];
            $remaining = PricingRule::minor($invoice->fresh()->remaining);
            if (config('settings.credits_auto_use', true) && $remaining > 0
                && $credit && PricingRule::minor($credit->amount) >= $remaining) {
                $credit->amount = PricingRule::decimal(PricingRule::minor($credit->amount) - $remaining); $credit->save();
                \App\Helpers\ExtensionHelper::addPayment($invoice->id, null, amount: PricingRule::decimal($remaining), isCreditTransaction: true);
            }
            (new RenewalAutoPay($this->clock))->issued($operation, $invoice->fresh(), $l, $actor === null);
            return $invoice->fresh();
        });
    }


    /** Renewal terms of 1 to 10 years whose new expiry stays within ten years of now. */
    public static function terms(\DateTimeImmutable $expiry, \DateTimeImmutable $now): array
    {
        $horizon = $now->modify('+10 years');
        return array_values(array_filter(range(1, 10), static fn (int $years) => \Carbon\CarbonImmutable::instance($expiry)->addYearsNoOverflow($years) <= $horizon));
    }

    /** Validated saved calendar, shared by billing and its month-out notice. */
    public static function calendarFor(array $l):array
    {
        $timing = BillingCalendar::timing($l['settings']);
        return self::calendar($l['domain'], $timing['issue'], $timing['due']);
    }

    /** Read-only eligibility shared by billing, owner previews and notices; writer callers hold HoldingLocks. */
    public static function requireAuthority(array $l,AccountBindings $bindings,bool $manual=false):void
    {
        $d=$l['domain'];$s=$l['service'];
        $bindings->requireProcessingLocked($l['server'],$l['account'], $l['advisory'] ?? false);
        if($d->renewal_policy['automatic_spend_paused']??false){throw new RuntimeIneligible(RuntimeReason::SpendingPaused);}
        if ($s->status !== 'active' || !in_array($d->status, ['active', 'expired', 'grace'], true)
            || ($manual ? RenewalStop::blocked($l) : RenewalStop::stopped($l)) || $d->price_mode !== 'standard') { throw new RuntimeIneligible(RuntimeReason::LifecycleNotReady); }
        $anchor = HoldingAnchor::resolve($l); $prior = $anchor->operation;
        if ($prior) { RenewalAuthority::requireCostAnchor($prior); }
        if ($l['operations']->contains(fn ($op) => $op->id !== $prior?->id && !HoldingAnchor::resolved($op, $l))) {
            throw new \DomainException('Confirmed renewal anchor or prior outcome requires review.');
        }
    }

    /** Validate retained debt without changing the invoice or operation. Writers pass their locked rows. */
    public static function existingInvoice(Operation $op, array $l, ?int $years = null): Invoice
    {
        $invoice = $l['invoices']->firstWhere('id', $op->invoice_id); $item = $l['items']->firstWhere('id', $op->invoice_item_id); $d = $l['domain'];
        if (!$invoice || !$item || !$invoice->due_at || !$op->base_expires_at || !$op->expected_expires_at || $l['items']->where('invoice_id', $op->invoice_id)->count() !== 1 || $op->action !== 'renew' || $op->funding_basis !== 'paid_invoice' || ($years !== null && (int) $op->term !== $years)
            || $op->service_id !== $d->service_id || $op->server_id !== $d->server_id || $op->registrar_key !== $d->registrar_key
            || $op->account_fingerprint !== $d->account_fingerprint || $op->authority_generation !== $d->authority_generation
            || $invoice->status === Invoice::STATUS_CANCELLED || (int) $invoice->user_id !== (int) $d->user_id || $invoice->currency_code !== 'USD'
            || (int) $item->invoice_id !== (int) $invoice->id || $item->reference_type !== null || $item->reference_id !== null
            || (string) $item->quantity !== '1' || PricingRule::minor($item->price) !== PricingRule::minor($op->amount)
            || PricingRule::minor($op->amount) <= 0 || $op->currency !== 'USD'
            || $op->base_expires_at?->format('Y-m-d') !== ($op->policy['calendar']['expiry'] ?? null)
            || $op->expected_expires_at?->format('Y-m-d') !== $op->base_expires_at?->addYearsNoOverflow((int) $op->term)->format('Y-m-d')
            || $invoice->due_at?->format('Y-m-d') !== ($op->policy['calendar']['due'] ?? null)) { throw new \DomainException('Existing renewal obligation requires review.'); }
        return $invoice;
    }

    /** Read-only owner request preview. The writer repeats every check under HoldingLocks. */
    public function requestOptions(Domain $d): array
    {
        return DB::transaction(function () use ($d): array {
            $l = HoldingLocks::take($d, advisory: true);
            $l['settings'] = $l['server']->settings()->orderBy('id')->get();
            // Replacement proof needs all invoice members, including unexpected retained items.
            $l['items'] = \App\Models\InvoiceItem::whereIn('invoice_id', $l['invoices']->pluck('id'))->orderBy('id')->get();
            RenewalCycles::next($l);
            $calendar = $this->requireRequest($l, true);
            $this->native->quote($l);
            return ['terms' => self::terms($l['domain']->provider_expires_at->toDateTimeImmutable(), $this->clock->now()),
                'order_by' => (new \DateTimeImmutable($calendar['expiry']))->modify('-1 day')->format('Y-m-d')];
        });
    }

    /** New-debt eligibility only. Existing invoices keep their own dates and terms. */
    private function requireRequest(array $l, bool $manual, ?int $years = null): array
    {
        self::requireAuthority($l, $this->bindings, $manual);
        $calendar = self::calendarFor($l); $settings = $l['settings']->pluck('value', 'key');
        if (!self::enabled($settings->get($manual ? 'domains_manual_renewals_enabled' : 'domains_billing_enabled', false))) {
            throw new RuntimeIneligible(RuntimeReason::BillingOff);
        }
        // No new Enom invoice for a renewal that can't go out, site gate and the other environment included. Issued invoices stay payable; paid renewals wait with their reason.
        if (EnomTestRenewal::holdsInvoices(CatalogueAcceptances::binding($l['account']))) { throw new RuntimeIneligible(RuntimeReason::BillingOff); }
        $today = $this->clock->now()->format('Y-m-d');
        if (($manual && self::expired($l['domain'], $this->clock->now())) || (!$manual && ($today >= $calendar['due'] || $today < $calendar['issue']))) { throw new RuntimeIneligible(RuntimeReason::OutsideCalendar); }
        $terms = self::terms($l['domain']->provider_expires_at->toDateTimeImmutable(), $this->clock->now());
        if ($terms === [] || ($years !== null && !in_array($years, $terms, true))) {
            throw new \DomainException('Renewal term exceeds the registry horizon.');
        }
        if ($manual) { $calendar['due'] = min($calendar['expiry'], max($calendar['due'], $today)); }
        return $calendar;
    }

    /** Billing uses the clock date and confirmed expiry date, without converting time zones. */
    public static function expired(Domain $domain, \DateTimeImmutable $now): bool
    {
        return $domain->provider_expires_at !== null && $now->format('Y-m-d') >= $domain->provider_expires_at->format('Y-m-d');
    }

    private static function enabled(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
