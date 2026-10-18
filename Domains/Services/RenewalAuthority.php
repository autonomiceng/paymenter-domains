<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Plan, Product, Server, Service, ServiceCancellation, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{Money, RenewalCode, RenewalRequest};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation, Price, Tld};
/** Owns the renewal-specific ordered prefix. No general callback or write-authority scope is exposed. */
final readonly class RenewalAuthority
{
    public function __construct(private AccountBindings $bindings, private Clock $clock) {}
    public function capture(RenewalRequest $request): array { return $this->locked($request); }
    public function recheck(RenewalRequest $request, string $stamp, RenewalAcceptance $accepted, \DateTimeImmutable $until): void
    {
        $this->locked($request, $stamp, $accepted, $until);
    }
    private function locked(RenewalRequest $request, ?string $stamp = null, ?RenewalAcceptance $accepted = null, ?\DateTimeImmutable $until = null): array
    {
        OperationClaims::outside();
        try {
            $seed = Domain::findOrFail($request->domainId); $route = Service::findOrFail($seed->service_id);
            return DB::transaction(function () use ($seed, $route, $request, $stamp, $accepted, $until): array {
                $user = User::whereKey($seed->user_id)->lockForUpdate()->firstOrFail();
                $service = Service::whereKey($seed->service_id)->lockForUpdate()->firstOrFail();
                $product = Product::whereKey($route->product_id)->lockForUpdate()->firstOrFail();
                $plan = Plan::whereKey($route->plan_id)->lockForUpdate()->firstOrFail();
                $cancel = ServiceCancellation::where('service_id', $service->id)->orderBy('id')->lockForUpdate()->get();
                $properties = $service->properties()->orderBy('id')->lockForUpdate()->get();
                $markers = $product->settings()->orderBy('id')->lockForUpdate()->get();
                $server = Server::whereKey($seed->server_id)->lockForUpdate()->firstOrFail();
                $account = Account::where('server_id', $seed->server_id)->lockForUpdate()->firstOrFail();
                $settings = $server->settings()->orderBy('id')->lockForUpdate()->get();
                $tld = Tld::where(['server_id' => $seed->server_id, 'tld' => $seed->tld])->lockForUpdate()->firstOrFail();
                $prices = Price::where(['server_id' => $seed->server_id, 'tld' => $seed->tld, 'action' => 'renew'])->orderBy('id')->lockForUpdate()->get();
                $domain = Domain::whereKey($seed->id)->lockForUpdate()->firstOrFail();
                $all = Operation::where('domain_id', $seed->id)->orderBy('id')->lockForUpdate()->get();
                if ($domain->user_id !== $seed->user_id || $domain->service_id !== $seed->service_id || $domain->server_id !== $seed->server_id
                    || $domain->tld !== $seed->tld || $service->product_id !== $route->product_id || $service->plan_id !== $route->plan_id) {
                    throw new RenewalRefused(RenewalCode::Changed);
                }
                $this->bindings->requireProcessingLocked($server, $account); $binding = CatalogueAcceptances::binding($account);
                $accepted?->requireCurrent($binding);
                $c = $domain->snapshot['checkout'] ?? [];
                if ($domain->account_fingerprint !== $binding->fingerprint || $domain->registrar_key !== $binding->registrarKey
                    || !$domain->billing_managed || $domain->reservation_name !== $domain->domain
                    || !in_array($domain->status, ['active', 'expired', 'grace'], true)
                    || (int) $service->user_id !== (int) $user->id || $service->status !== Service::STATUS_ACTIVE
                    || $service->expires_at !== null || $service->currency_code !== 'USD' || (string) $service->quantity !== '1'
                    || $cancel->isNotEmpty() || (int) $product->server_id !== (int) $domain->server_id
                    || ($c['service_id'] ?? null) !== (int) $service->id || ($c['user_id'] ?? null) !== (int) $user->id
                    || ($c['product_id'] ?? null) !== (int) $product->id || ($c['annual_plan_id'] ?? null) !== (int) $plan->id
                    || ($c['name'] ?? null) !== $domain->domain || ($c['order_id'] ?? null) !== (int) $service->order_id
                    || $plan->priceable_type !== $product->getMorphClass() || (int) $plan->priceable_id !== (int) $product->id
                    || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || (int) $plan->billing_period !== 1
                    || $tld->account_fingerprint !== $binding->fingerprint || $tld->registrar_key !== $binding->registrarKey
                    || $properties->where('key', 'domain')->count() !== 1 || $properties->firstWhere('key', 'domain')->value !== $domain->domain) {
                    throw new RenewalRefused(RenewalCode::Authority);
                }
                foreach (['domains_catalogue' => '1', 'domains_tld_id' => (string) $tld->id, 'domains_action' => $c['action'] ?? null, 'domains_registrar' => $binding->registrarKey] as $key => $value) {
                    $rows = $markers->where('key', $key);
                    if ($value === null || $rows->count() !== 1 || $rows->first()->value !== $value || $rows->first()->encrypted || $rows->first()->type !== 'string') {
                        throw new RenewalRefused(RenewalCode::Authority);
                    }
                }
                if ($domain->authority_generation !== $request->generation || $domain->confirmed_revision !== $request->revision
                    || $domain->provider_expires_at?->format('Y-m-d') !== $request->expiry || $domain->provider_domain_id !== $request->providerId
                    || ($binding->registrarKey === 'enom' && !preg_match('/^[1-9][0-9]{0,19}$/D', $request->providerId ?? ''))) {
                    throw new RenewalRefused(RenewalCode::Anchor);
                }
                if ($domain->renewal_policy['automatic_spend_paused'] ?? false) { throw new RenewalRefused(RenewalCode::Paused); }
                $anchor = $domain->renewal_policy['billing_anchor'] ?? [];
                $prior = $all->firstWhere('operation_key', $anchor['operation_key'] ?? null);
                if (($anchor['expiry'] ?? null) !== $request->expiry || !$prior || !$this->completed($prior, $domain, $binding)
                    || $all->contains(fn ($op) => $op->id !== $prior->id && !OperationTerminalProof::safe($op, $domain))) {
                    throw new RenewalRefused(RenewalCode::Prior);
                }
                // Ciphertext hashes detect even same-account verified credential rotation during the read window.
                $rows = [$user, $service, $product, $plan, $server, $account, $tld, $domain,
                    ...$cancel, ...$properties, ...$markers, ...$settings, ...$prices, ...$all];
                $current = hash('sha256', json_encode(array_map(static fn ($row) => $row->getRawOriginal(), $rows), JSON_THROW_ON_ERROR));
                if (($stamp !== null && $stamp !== $current) || ($until !== null && $until <= $this->clock->now())) {
                    throw new RenewalRefused(RenewalCode::Changed);
                }
                $annual = $prices->where('term', 1)->where('currency', 'USD')->where('account_fingerprint', $binding->fingerprint)->first();
                $retail = $annual?->retail !== null ? PricingRule::total(PriceCatalogue::retail($annual->retail), $request->years) : null;
                $admission = $prior->attempt['registration_admission'] ?? [];
                $nonpremium = false;
                try {
                    $nonpremium = ($admission['premium'] ?? null) === false && ($admission['name'] ?? null) === $domain->domain
                        && ($admission['years'] ?? null) === (int) $prior->term
                        && ($admission['cost'] ?? null) === ($prior->attempt['authorized_cost'] ?? null)
                        && ($admission['cost']['currency'] ?? null) === 'USD'
                        && WriteMoney::compare((new Money($prior->cost['actual']['amount'] ?? '', 'USD'))->amount,
                            (new Money($admission['cost']['amount'] ?? '', 'USD'))->amount) === 0;
                } catch (\InvalidArgumentException | \TypeError) {}
                return [$binding, $domain->domain, $current, $retail, $nonpremium];
            });
        } catch (RenewalRefused $error) { throw $error; }
        catch (\Throwable) { throw new RenewalRefused(RenewalCode::Authority); }
    }
    private function completed(Operation $op, Domain $domain, $binding): bool
    {
        $p = $op->attempt['reconciliation'] ?? [];
        if ($op->state !== 'succeeded' || $op->action !== 'register' || $op->service_id !== $domain->service_id
            || $op->server_id !== $domain->server_id || $op->registrar_key !== $domain->registrar_key
            || $op->account_fingerprint !== $binding->fingerprint || $op->authority_generation !== $domain->authority_generation
            || $op->confirmed_expires_at?->format('Y-m-d') !== $domain->provider_expires_at?->format('Y-m-d')
            || ($p['expiry'] ?? null) !== $domain->provider_expires_at?->format('Y-m-d')
            || ($p['provider_domain_id'] ?? null) !== $domain->provider_domain_id || ($p['fingerprint'] ?? null) !== $binding->fingerprint
            || ($p['generation'] ?? null) !== $domain->authority_generation || ($p['operation_key'] ?? null) !== $op->operation_key
            || ($p['charge'] ?? null) !== ($op->cost['actual'] ?? null) || ($p['charge']['currency'] ?? null) !== 'USD'
            || ($p['query'] ?? null) !== ['name' => $domain->domain, 'action' => $op->action, 'years' => (int) $op->term]
            || !in_array($p['source'] ?? null, ['captured_order', 'accepted_history'], true)) { return false; }
        // Reuse the original sealed-attempt/PersistedExecution reconstruction, never a state-label shortcut.
        try {
            // A history-discovered ID is completion proof, not a retroactive original response ID.
            $original = clone $op;
            $reference = $op->attempt['registration']['order_reference'] ?? $op->attempt['evidence']['registration']['orderReference'] ?? null;
            if ($p['source'] === 'accepted_history' && $reference === null) { $original->provider_order_id = null; }
            $attempt = ReconciliationEvidence::attempt($original, $domain, $binding);
            $processed = new \DateTimeImmutable($p['processed_at'] ?? 'invalid');
            if (($p['window_start'] ?? null) !== $attempt->windowStart->format(DATE_ATOM)
                || ($p['window_end'] ?? null) !== $attempt->windowEnd->format(DATE_ATOM)
                || $processed < $attempt->windowStart || $processed > $attempt->windowEnd) { return false; }
            if ($p['source'] === 'captured_order') {
                if (!$attempt->executionFacts) { return false; }
                $attempt->executionFacts->requireAttempt($attempt);
            }
            return ($p['order_reference'] ?? null) === $op->provider_order_id;
        } catch (\Throwable) { return false; }
    }
}
