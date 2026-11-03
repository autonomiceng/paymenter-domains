<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts, Context, OfferState, Query, Revision, UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Data\SaleSelection;
use Paymenter\Extensions\Servers\Domains\Models\{Account, SalesPolicy, Tld};

/** Dormant commerce adapter. No registration, provider factory, activation or price fallback.
 * Native checkout owns ALL native locks before admit(); the reader takes the whole catalogue prefix. */
final class AcquisitionOffers
{
    public function __construct(private readonly CallerPorts $ports, private readonly AccountBindings $bindings,
        private readonly NativeCatalogue $native) {}

    public function selection(string $ending, string $action, int $term): SaleSelection
    { return $this->offers([[$ending, $action, $term]])[0]['selection']; }

    public function offer(string $ending, string $action, int $term): array
    { return $this->offers([[$ending, $action, $term]])[0]; }

    public function transfersOn(string $ending): bool
    { try { $this->offer($ending, 'transfer', 1); return true; } catch (\DomainException) { return false; } }

    /** Public batches resolve ONLY the selected account, never a cheaper candidate. */
    public function offers(array $requests): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Public offers require unlocked reads.'); }
        $selections = [];
        foreach ($requests as [$ending, $action, $term]) {
            $policy = SalesPolicy::where('tld', $ending)->sole();
            $account = Account::where('server_id', $policy->server_id)->sole();
            $b = CatalogueAcceptances::binding($account);
            if ($b->fingerprint !== $policy->account_fingerprint || $b->registrarKey !== $policy->registrar_key) { throw new \DomainException('Seller changed.'); }
            $selections[] = new SaleSelection($ending, $b->serverId, $b->registrarKey, $b->fingerprint,
                $policy->revision, $action, $term, 'USD', 0, 0);
        }
        $batch = $this->load($selections, false, false); $result = [];
        foreach ($batch as $i => $row) {
            $s = $row['selection']; $tld = Tld::where(['server_id' => $s->serverId, 'tld' => $s->tld])->sole();
            $product = $this->native->product($tld, $s->action);
            if (!$product || $product->stock !== null) { throw new \DomainException('Offer unavailable.'); }
            $plan = $product->plans()->where('billing_period', $s->term)->sole(); $this->native->mapping($product, $plan);
            $native = $plan->prices()->where('currency_code', 'USD')->sole();
            if (RetailLine::decimal($native->price) !== $row['snapshot']->retail || RetailLine::decimal($native->setup_fee) !== '0.00') {
                throw new \DomainException('Native offer changed.');
            }
            $result[] = ['selection' => $s, 'product' => $product, 'plan' => $plan,
                'price' => Storefront::money($row['snapshot']->retail), 'renew' => Storefront::money($row['renewal']->retail)];
        }
        return $result;
    }

    public function admit(array $selections): array
    {
        if (DB::transactionLevel() === 0) { throw new \LogicException('Admission requires native locks.'); }
        return $this->load($selections, true, true);
    }

    /** Receipt reuse and post-provider reads validate locally, without another provider call. */
    public function validate(SaleSelection $selection, bool $locked = false): void
    { $this->load([$selection], $locked, true); }

    private function load(array $selections, bool $locked, bool $expected): array
    {
        if (!$selections || count($selections) > 250) { throw new \DomainException('Invalid acquisition batch.'); }
        $queries = []; $bindings = [];
        foreach ($selections as $s) {
            $a = Account::where('server_id', $s->serverId)->sole(); $b = CatalogueAcceptances::binding($a);
            if ($b->fingerprint !== $s->fingerprint || $b->registrarKey !== $s->registrar) { throw new \DomainException('Offer binding changed.'); }
            $bindings[] = $b;
            $revision = $expected ? new Revision(...($s->catalogue['revision'] ?? throw new \DomainException('Refresh the domain offer.'))) : null;
            foreach ([[$s->action, $s->term], ['renew', 1]] as [$action, $term]) {
                $queries[] = new Query($b, [$s->tld], $action, $term, $s->currency, $revision ? [$s->tld => $revision] : []);
            }
        }
        $loaded = $this->ports->loadBatch($queries, $locked ? Context::Locked : Context::Public);
        // All servers/accounts are now locked in batch order, including other cart accounts.
        $generation = UpgradeGate::generation(); $result = [];
        foreach ($selections as $i => $s) {
            $acquire = $loaded[2*$i][0]; $renew = $loaded[2*$i+1][0];
            $price = $acquire->price(); $annual = $renew->price();
            $fence = ['generation' => $generation, 'credential' => $this->bindings->offerCredentialRevision($bindings[$i]),
                'revision' => get_object_vars($acquire->decision->revision), 'retail' => $price->retail,
                'renewal' => $annual->retail];
            if ($expected && ($s->catalogue !== $fence || $s->priceId !== $price->id || $s->priceRevision !== $price->revision
                || $s->policyRevision !== $acquire->decision->revision->selection)) {
                throw new \DomainException('Refresh the domain offer.');
            }
            $selected = new SaleSelection($s->tld, $s->serverId, $s->registrar, $s->fingerprint,
                $acquire->decision->revision->selection, $s->action, $s->term, $s->currency, $price->id, $price->revision, $fence);
            $result[] = ['selection' => $selected, 'snapshot' => $price, 'renewal' => $annual];
        }
        return $result;
    }

    /** Contact validation consumes the independently observed registry schema, never old TLD facts.
     * A transient copy keeps native contact/tax/owner validation intact without a legacy write. */
    public function contactTld(Tld $tld): Tld
    {
        $facts = OfferState::facts($tld->catalogue_facts);
        if (!$tld->catalogue_converted || !$facts || !RegistryInputs::supported($tld->registrar_key, $tld->tld, $facts->registryFields)) {
            throw new \DomainException('Current registry details unavailable.');
        }
        $copy = clone $tld; $copy->registry_fields = $facts->registryFields;
        return $copy;
    }

    /** Display caches are hints only. Include credential/activation and unpublished changes too. */
    public function cacheFence(): string
    {
        $credentials = []; $ids = SalesPolicy::pluck('server_id')->filter()->unique()->all();
        foreach (Account::whereIn('server_id', $ids)->orderBy('server_id')->get() as $account) {
            $credentials[] = $this->bindings->offerCredentialRevision(CatalogueAcceptances::binding($account));
        }
        $rows = []; $servers = (new \App\Models\Server)->getTable();
        foreach (['domains_accounts', 'domains_sales_policies', 'domains_tlds', 'domains_prices', $servers, 'settings'] as $table) {
            $query = DB::table($table);
            if ($table === 'settings') { $query->where('settingable_type', (new \App\Models\Server)->getMorphClass())->whereIn('settingable_id', $ids); }
            elseif ($table !== 'domains_sales_policies') { $query->whereIn($table === $servers ? 'id' : 'server_id', $ids); }
            $rows[] = $query->orderBy('id')->get()->map(static fn ($r) => (array)$r)->all();
        }
        return hash('sha256', OfferState::json([UpgradeGate::generation(), $credentials, $rows]));
    }
}
