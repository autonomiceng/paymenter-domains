<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Models\{Invoice, Service, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, Environment};
use Paymenter\Extensions\Servers\Domains\Data\Adoption\{StaffContext, Target};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;

/** Unlocked preview only. HoldingIdentityLock remains the future commit authority. */
final class AdoptionMapping
{
    public function __construct(private readonly NativeCatalogue $native, private readonly PriceCatalogue $prices, private readonly Clock $clock) {}
    public function capture(Target $target, StaffContext $staff): array
    {
        $service = Service::find($target->serviceId);
        $matches = Domain::where(fn ($q) => $q->where('reservation_name', $target->name)->orWhere('domain', $target->name))->limit(2)->get();
        if ($matches->count() > 1) { throw new \DomainException('competing_holding'); }
        $domain = $matches->first();
        if (!$service || (int) $service->user_id !== $target->customerId) { throw new \DomainException('mapping_unconfirmed'); }
        $l = HoldingNativeLocks::take($target->customerId, $target->serviceId, $staff->actorId, true, $domain);
        $service = $l['service']; $order = $l['orders']->firstWhere('id', $service->order_id);
        if (!$order || (int) $order->user_id !== $target->customerId || (int) $service->user_id !== $target->customerId
            || $service->quantity !== 1 || $service->currency_code !== 'USD' || $order->currency_code !== 'USD'
            || !in_array($service->status, ['pending', 'active', 'suspended'], true) || $l['configs']->isNotEmpty()
            || $l['cancel']->contains(fn ($c) => $c->type !== 'end_of_period') || $service->upgrade()->exists()) {
            throw new \DomainException('mapping_unconfirmed');
        }
        if ($service->expires_at !== null) { throw new \DomainException('native_expiry_present'); }
        $mapping = $this->native->mapping($l['product'], $l['plan']);
        $annual = $this->native->annualPlan($l['product']);
        $tld = $mapping['tld'] ?? null;
        $properties = $l['properties']->where('key', 'domain');
        if (!$tld || ($mapping['action'] ?? null) !== 'register' || (int) $annual->id !== (int) $service->plan_id || (int) $tld->server_id !== $target->serverId
            || $properties->count() !== 1 || $properties->first()->value !== $target->name) { throw new \DomainException('mapping_unconfirmed'); }
        CanonicalName::parse($target->name, [$tld->tld]);
        try { $account = Account::where('server_id', $target->serverId)->sole(); }
        catch (\Throwable) { throw new \DomainException('account_unconfirmed'); }
        $binding = CatalogueAcceptances::binding($account);
        if ($binding->registrarKey !== 'enom' || $binding->environment !== $target->environment
            || $binding->fingerprint !== $tld->account_fingerprint) { throw new \DomainException('account_unconfirmed'); }
        if ($domain && !$domain->adoptionPending()) { throw new \DomainException('competing_holding'); }
        if ($domain && ((int) $domain->service_id !== $target->serviceId || (int) $domain->user_id !== $target->customerId
            || (int) $domain->server_id !== $target->serverId || $domain->registrar_key !== 'enom'
            || $domain->account_fingerprint !== $binding->fingerprint)) { throw new \DomainException('mapping_unconfirmed'); }
        $sourceExpiry = $domain?->renewal_policy['conversion_source_expiry'] ?? $domain?->provider_expires_at?->format('Y-m-d');
        if ($domain) {
            try { new \Paymenter\Extensions\Servers\Domains\Data\DateEvidence($sourceExpiry ?? '', \Paymenter\Extensions\Servers\Domains\Data\ExpiryPrecision::Date, 'conversion_source'); }
            catch (\Throwable) { throw new \DomainException('expiry_unconfirmed'); }
            if ($domain->provider_expires_at !== null && $sourceExpiry !== $domain->provider_expires_at->format('Y-m-d')) { throw new \DomainException('expiry_disagrees'); }
        }
        if (Domain::where('service_id', $service->id)->when($domain, fn ($q) => $q->whereKeyNot($domain->id))->exists()) { throw new \DomainException('competing_holding'); }
        // Canonical syntax permits surrounding ASCII spaces and one trailing dot; include both candidate spellings.
        $peers = Service::whereKeyNot($service->id)
            ->whereHas('properties', fn ($q) => $q->where('key', 'domain')->whereRaw('lower(trim(value)) in (?, ?)', [$target->name, $target->name.'.']))->orderBy('id')->limit(101)->get();
        if ($peers->count() > 100) { throw new \DomainException('competing_holding'); }
        $peerStamps = [];
        foreach ($peers as $peer) {
            $peerNames = $peer->properties()->where('key', 'domain')->orderBy('id')->limit(101)->get();
            if ($peerNames->count() > 100 || !$peerNames->contains(function ($property) use ($target): bool {
                try { return CanonicalName::normalize($property->value) === $target->name; } catch (\Throwable) { return false; }
            })) { throw new \DomainException('competing_holding'); }
            $p = $peer->product; $server = $p?->server;
            $watch = $p ? \Paymenter\Extensions\Servers\Domains\Models\WatchProductMap::where('product_id', $p->id)->first() : null;
            if (!$p || $p->settings()->whereIn('key', ['domains_catalogue', 'domains_tld_id', 'domains_action', 'domains_registrar'])->exists()
                || ($p->server_id !== null && (!$watch || !$server || in_array(strtolower($server->extension), ['domains', 'enom', 'namesilo'], true)))) {
                throw new \DomainException('competing_holding');
            }
            $peerStamps[] = self::stamp([$peer->getRawOriginal(), $p->getRawOriginal(), $server?->getRawOriginal(), $watch?->getRawOriginal(), $peerNames->map(fn ($n) => $n->getRawOriginal())->all()]);
        }
        $ops = Operation::where(fn ($q) => $q->where('service_id', $service->id)->when($domain, fn ($q) => $q->orWhere('domain_id', $domain->id)))->orderBy('id')->limit(101)->get();
        $items = $service->invoiceItems()->orderBy('id')->limit(101)->get();
        $invoices = Invoice::whereIn('id', $items->pluck('invoice_id'))->orderBy('id')->get();
        if ($ops->count() > 100 || $items->count() > 100 || $ops->contains(fn ($op) => !$domain || !OperationTerminalProof::safe($op, $domain))
            || $invoices->count() !== $items->pluck('invoice_id')->unique()->count()
            || $invoices->contains(fn ($i) => (int) $i->user_id !== $target->customerId || !in_array($i->status, ['paid', 'cancelled'], true))) {
            throw new \DomainException('unresolved_debt');
        }
        try {
            $accepted = CatalogueAcceptances::load($binding);
            $ending = $accepted->endings[$tld->tld] ?? null;
            if (!$tld->renew_enabled || !$ending || !$ending['renewal_safe'] || !in_array(1, $ending['renew'], true)) { throw new \DomainException; }
            $retail = $this->prices->renewal($binding->serverId, $binding->fingerprint, $tld->tld, 'USD', $this->clock->now());
            $gross = NativeDomainGuard::gross($retail->retail, $l['tax']);
        } catch (\Throwable) { throw new \DomainException('price_unconfirmed'); }
        $settings = \App\Models\Server::findOrFail($binding->serverId)->settings()->orderBy('id')->get();
        $calendarRows = $settings->whereIn('key', ['domains_issue_days', 'domains_due_days']);
        try {
            if ($calendarRows->groupBy('key')->contains(fn ($r) => $r->count() !== 1)) { throw new \DomainException; }
            $timing = BillingCalendar::timing($settings);
        } catch (\Throwable) { throw new \DomainException('price_unconfirmed'); }
        $history = $items->sort(fn ($a, $b) => [(int) $a->invoice_id, (int) $a->id] <=> [(int) $b->invoice_id, (int) $b->id])->unique(fn ($i) => $i->invoice_id.':'.$i->id)->values()->map(fn ($item) => ['invoice_id' => (int) $item->invoice_id, 'invoice_item_id' => (int) $item->id])->all();
        $native = ['user_id' => $target->customerId, 'service_id' => $service->id, 'order_id' => $order->id,
            'product_id' => $l['product']->id, 'annual_plan_id' => $annual->id, 'server_id' => $binding->serverId, 'route_action' => $mapping['action'], 'catalogue_slug' => $l['product']->slug,
            'domain_property_id' => $properties->first()->id, 'domain_property' => $target->name];
        // Raw model attributes are hashed locally; encrypted setting values and native PII never enter evidence.
        $stamps = [];
        foreach (['user', 'staff', 'orders', 'services', 'products', 'plans', 'cancel', 'configs', 'properties', 'markers', 'upgrades', 'links', 'tax'] as $key) {
            $value = $l[$key];
            $stamps[$key] = self::stamp($value instanceof \Illuminate\Support\Collection ? $value->map(fn ($v) => method_exists($v, 'getRawOriginal') ? $v->getRawOriginal() : (array) $v)->all()
                : (is_object($value) ? $value->getRawOriginal() : $value));
        }
        $stamps['role'] = self::stamp($l['staff']?->role?->getRawOriginal());
        $stamps['owner_properties'] = self::stamp($l['user']->properties->map(fn ($p) => $p->getRawOriginal())->all());
        foreach (['account' => $account, 'tld' => $tld, 'domain' => $domain, 'price' => \Paymenter\Extensions\Servers\Domains\Models\Price::find($retail->id),
            'server' => \App\Models\Server::find($binding->serverId)] as $key => $model) { $stamps[$key] = self::stamp($model?->getRawOriginal()); }
        foreach (['settings' => $settings, 'operations' => $ops, 'items' => $items, 'invoices' => $invoices] as $key => $rows) {
            $stamps[$key] = self::stamp($rows->map(fn ($r) => $r->getRawOriginal())->all());
        }
        $stamps['peer_sources'] = self::stamp($peerStamps);
        $stamps['site'] = self::stamp(config('app.url'));
        return ['binding' => $binding, 'domain_id' => $domain?->id, 'native_identity' => $native, 'suffix' => $tld->tld,
            'existing_do_not_renew' => $l['cancel']->contains('service_id', $service->id), 'expected_provider_id' => $domain?->provider_domain_id,
            'expected_expiry' => $sourceExpiry, 'stamps' => $stamps,
            'prior_generation' => $domain?->authority_generation ?? 0, 'prior_revision' => $domain?->confirmed_revision ?? 0,
            'retail' => ['price_id' => $retail->id, 'revision' => $retail->revision, 'annual_net' => $retail->retail, 'annual_gross' => $gross, 'currency' => 'USD', 'tax_basis' => 'native_exclusive_once', 'tax_digest' => $stamps['tax'], 'retained' => $retail->retained, 'staff_alert' => $retail->staffAlert],
            'calendar_timing' => $timing, 'history_link_ids' => $history,
            'history_review' => $invoices->map(fn ($i) => ['invoice_id' => (int) $i->id, 'status' => $i->status])->all()];
    }
    public static function stamp(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
}
