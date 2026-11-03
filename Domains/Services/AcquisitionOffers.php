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

    /** Per-invocation display only. Shared evaluator and batched native mapping, never cart authority. */
    public function display(array $endings): array
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Display requires unlocked reads.'); }
        $policies = SalesPolicy::whereIn('tld', $endings)->get();
        $ids = $policies->pluck('server_id')->filter()->unique();
        $accounts = Account::whereIn('server_id', $ids)->get()->keyBy('server_id');
        $tlds = Tld::whereIn('server_id', $ids)->whereIn('tld', $endings)->get()->keyBy(static fn ($t) => $t->server_id.':'.$t->tld);
        $native = $this->native->displayBatch($tlds->all()); $generation = UpgradeGate::generation();
        $result = [];
        foreach ($policies->groupBy('server_id') as $id => $group) {
            try {
                $b = CatalogueAcceptances::binding($accounts[$id]);
                $credential = $this->bindings->offerCredentialRevision($b);
                foreach (array_chunk($group->pluck('tld')->all(), 500) as $names) {
                    foreach ($this->ports->displayBatch([new Query($b, $names, 'register')])[0] as $name => $rows) {
                        $tld = $tlds[$id.':'.$name] ?? null;
                        foreach ($rows as $key => $row) {
                            if ($key === 'renew:1') { continue; }
                            try {
                                $price = $row->price(); $annual = $rows['renew:1']->price();
                                $action = $price->action; $term = $price->term;
                                $product = $native[$tld->id][$action]['product'] ?? null;
                                $plan = $native[$tld->id][$action]['plans'][$term] ?? null;
                                if (!$product || !$plan || $product->stock !== null) { continue; }
                                $usd = $plan->prices->where('currency_code', 'USD')->sole();
                                if (RetailLine::decimal($usd->price) !== $price->retail || RetailLine::decimal($usd->setup_fee) !== '0.00') { continue; }
                                $revision = $row->decision->revision;
                                $s = new SaleSelection($name, $b->serverId, $b->registrarKey, $b->fingerprint,
                                    $revision->selection, $action, $term, 'USD', $price->id, $price->revision,
                                    ['generation' => $generation, 'credential' => $credential, 'revision' => get_object_vars($revision),
                                        'retail' => $price->retail, 'renewal' => $annual->retail]);
                                $result[$name][$key] = ['selection' => $s, 'product' => $product, 'plan' => $plan,
                                    'price' => Storefront::money($price->retail), 'renew' => Storefront::money($annual->retail)];
                            } catch (\Throwable) { /* This ending/term is unavailable; keep healthy siblings. */ }
                        }
                    }
                }
            } catch (\Throwable) { /* Missing/disabled/invalid account withdraws only its own endings. */ }
        }
        return $result;
    }

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

    /** Writer commits advance the shared display revision (including pending publication/facts).
     * Hits read account state only; raw unrevisioned offer maintenance retains the display TTL bound.
     * Encrypted credential/enrollment/settings bytes fence rotation even when the account is unavailable. */
    public function cacheFence(): string
    {
        $ids = SalesPolicy::distinct()->pluck('server_id')->filter()->all();
        $rows = []; $servers = (new \App\Models\Server)->getTable();
        foreach (['domains_accounts', $servers, 'settings'] as $table) {
            $query = DB::table($table);
            if ($table === 'settings') { $query->where('settingable_type', (new \App\Models\Server)->getMorphClass())->whereIn('settingable_id', $ids); }
            else { $query->whereIn($table === $servers ? 'id' : 'server_id', $ids); }
            $rows[] = $query->orderBy('id')->get()->map(static fn ($r) => (array)$r)->all();
        }
        return hash('sha256', OfferState::json([UpgradeGate::generation(), $rows]));
    }
}
