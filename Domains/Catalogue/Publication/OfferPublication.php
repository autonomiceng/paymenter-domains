<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Publication;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Command, Edit, FeedObserved, Intent, OfferState, Projection, Revision};
use Paymenter\Extensions\Servers\Domains\Catalogue\Pricing\PriceWriter;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, StaffSettingsReview, StorefrontCatalogueCache};

/** One offer group's write inside the caller's transaction: locks, revision check, mutation, native publication and audit. */
final class OfferPublication
{
    private readonly PriceWriter $prices;

    public function __construct(private readonly Projection $projection, private readonly Clock $clock,
        private readonly \Closure $authorize, private readonly \Closure $ready, private readonly ?int $actorId, private readonly ?\Closure $current)
    { $this->prices = new PriceWriter; }

    public function write(Command $command, Revision $expectedRevision, array $seeds, ?array $native, bool $reviewed = false): Revision
    {
        $binding = $command->binding(); $name = $command->tld();
        $seller = $command instanceof Edit && $command->publishesSeller();
        [$servers, $accounts] = $this->lockAccounts($command, $seeds, $seller);
        $ids = array_keys($servers);
        $policy = DB::table('domains_sales_policies')->where('tld', $name)->lockForUpdate()->first();
        $created = $this->create($seeds, $binding, $name);
        $offers = Tld::where('tld', $name)->whereIn('server_id', $ids)->select('*')->selectRaw('CAST(catalogue_retained_annual_retail AS CHAR) AS catalogue_retained_annual_retail')->orderBy('id')->lockForUpdate()->get();
        $rows = OfferState::query('domains_prices')->whereIn('server_id', $ids)->where('tld', $name)->orderBy('id')->lockForUpdate()->get();
        $target = $offers->firstWhere('server_id', $binding->serverId) ?? throw new \DomainException('Offer disappeared.');
        $account = $accounts[$binding->serverId];
        $defaultsEdit = $command instanceof Edit && $command->kind === 'defaults';
        $defaults = $defaultsEdit ? OfferState::replaceableDefaults($account->catalogue_defaults, $binding->serverId) : OfferState::decode($account->catalogue_defaults);
        $revision = OfferState::revision($target, $defaults, $policy); $revision->requireMatch($expectedRevision);
        OfferTerms::validateGroup($offers, $rows, $servers, $accounts);
        // Reauthorize after taking mutexes, before any command mutation.
        ($this->authorize)($command, null); $now = $this->clock->now();
        if ($this->current) { ($this->current)(); }
        if ($defaultsEdit) { return $this->saveDefaults($command, $servers[$binding->serverId], $account, $revision, $now, $reviewed); }
        if ($command instanceof Edit && $command->kind === 'sync') { return $revision; }
        $preserveRetained = $command instanceof Edit && ($command->kind === 'seller' || $command->kind === 'override'
            && ($command->rule === (OfferState::decode($target->catalogue_intent)['pricing_rule'] ?? null)));
        $changed = (new OfferMutation($this->clock, $this->prices))->apply($command, $target, $rows->where('server_id', $binding->serverId), $now) || $created;
        if (!$changed) { return $revision; }
        $policy = $this->select($command, $target, $policy, $revision, $now, $seller);
        foreach ($seller ? $offers : [$target] as $offer) {
            $this->publish($command, $offer, $servers, $accounts, $policy, $native, $preserveRetained, $now);
        }
        $result = OfferState::revision($target, $defaults, $policy);
        if (!$reviewed) { $this->audit($servers[$binding->serverId], $command, $revision, $result, $now); }
        $this->afterCommit();
        return $result;
    }

    /** Lock every server and account in the group, in id order, and recheck the command's binding.
     * @return array{0:array<int,Server>,1:array<int,Account>} */
    private function lockAccounts(Command $command, array $seeds, bool $seller): array
    {
        $binding = $command->binding();
        if ($seller && Tld::where('tld', $command->tld())->orderBy('id')->pluck('id')->all() !== array_map(static fn ($s) => $s->id, $seeds)) {
            throw new \DomainException('Seller offer group changed; retry.');
        }
        $ids = array_values(array_unique(array_map(static fn ($s) => (int) $s->server_id, $seeds))); sort($ids, SORT_NUMERIC);
        $servers = $accounts = [];
        foreach ($ids as $id) { $servers[$id] = Server::whereKey($id)->lockForUpdate()->firstOrFail(); }
        foreach ($ids as $id) { $accounts[$id] = Account::where('server_id', $id)->lockForUpdate()->sole(); }
        if (!isset($accounts[$binding->serverId])) { throw new \DomainException('Seller offer missing.'); }
        if ($seller && $command->actions !== [] && !$servers[$binding->serverId]->enabled) {
            throw new \DomainException('Seller account off.');
        }
        $binding->requireMatch(AccountBindings::binding($accounts[$binding->serverId]));
        if ($this->current) { ($this->current)(); }
        return [$servers, $accounts];
    }

    /** A feed or facts observation for a new ending creates its offer row. */
    private function create(array $seeds, AccountBinding $binding, string $name): bool
    {
        if ($seeds[0]->exists || Tld::where('server_id', $binding->serverId)->where('tld', $name)->exists()) { return false; }
        DB::table('domains_tlds')->insert(['server_id' => $binding->serverId, 'registrar_key' => $binding->registrarKey,
            'account_fingerprint' => $binding->fingerprint, 'tld' => $name, 'catalogue_created' => true,
            'catalogue_intent' => OfferState::json(new Intent)]);
        return true;
    }

    /** Account defaults contain selling intent only. Rule and fee have one global store. */
    private function saveDefaults(Edit $command, Server $server, Account $account, Revision $revision, \DateTimeImmutable $now, bool $reviewed): Revision
    {
        $defaults = OfferState::nextDefaults($command->intent, $account->catalogue_defaults, $revision->defaults);
        DB::table('domains_accounts')->where('server_id', $command->binding()->serverId)->update(['catalogue_defaults' => OfferState::json($defaults)]);
        $next = new Revision($revision->offer, $revision->defaults + 1, $revision->selection);
        if (!$reviewed) { $this->audit($server, $command, $revision, $next, $now); }
        // Every offer republishes under the new defaults. Until then each one sells only what both the old and
        // the new defaults allow, so already sold TLDs stay on sale.
        $this->afterCommit();
        return $next;
    }

    /** Seller selection and the explicit transfer switch, for the same chosen registrar only. */
    private function select(Command $command, Tld $target, ?object $policy, Revision $revision, \DateTimeImmutable $now, bool $seller): ?object
    {
        $binding = $command->binding(); $name = $command->tld();
        if ($command instanceof Edit && $command->sellerTransfers !== null && $policy
            && (bool) $policy->transfer_enabled !== $command->sellerTransfers
            && (int) $policy->server_id === $binding->serverId && $policy->account_fingerprint === $binding->fingerprint
            && $policy->registrar_key === $binding->registrarKey && (!$command->sellerTransfers || OfferTerms::transfersSupported($target, $now))) {
            // An explicit transfer switch updates only the same chosen registrar; registrations stay as they were.
            DB::table('domains_sales_policies')->where('id', $policy->id)->update(['transfer_enabled' => $command->sellerTransfers, 'revision' => $revision->selection + 1, 'updated_at' => $now]);
            $policy = DB::table('domains_sales_policies')->where('tld', $name)->first();
        }
        if (!$seller) { return $policy; }
        $values = ['server_id' => $command->actions ? $binding->serverId : null,
            'registrar_key' => $command->actions ? $binding->registrarKey : null,
            'account_fingerprint' => $command->actions ? $binding->fingerprint : null,
            'register_enabled' => in_array('register', $command->actions, true),
            'transfer_enabled' => in_array('transfer', $command->actions, true), 'revision' => $revision->selection + 1, 'updated_at' => $now];
        if ($policy) { DB::table('domains_sales_policies')->where('id', $policy->id)->update($values); }
        else { DB::table('domains_sales_policies')->insert(['tld' => $name, 'created_at' => $now, ...$values]); }
        return DB::table('domains_sales_policies')->where('tld', $name)->first();
    }

    /** Publish one offer's prices and native projection, then persist its checked revisions. */
    private function publish(Command $command, Tld $offer, array $servers, array $accounts, ?object $policy, ?array $native, bool $preserveRetained, \DateTimeImmutable $now): void
    {
        $id = (int) $offer->server_id; $account = $accounts[$id]; $b = AccountBindings::binding($account);
        $retailUpgrade = $offer->catalogue_published_revision !== null && !OfferState::currentPublication($offer->catalogue_published_revision);
        $offer->catalogue_revision = (int) $offer->catalogue_revision + 1;
        $annual = $offer->catalogue_retained_annual_retail;
        $published = $command instanceof Edit && $command->kind === 'retail' ? $this->prices->rule($offer) !== null : $this->prices->publish($offer, $now);
        // Keep unreadable own pricing closed, including when the feed/facts change.
        if (!$published) { $offer->catalogue_published_revision = null; }
        if ($preserveRetained || $command instanceof Edit && $command->kind === 'sales_setup'
            && ($id !== $command->binding()->serverId || !$command->ruleChanged)) { $offer->catalogue_retained_annual_retail = $annual; }
        // A damaged saved cost stays out of the snapshot, so only its own offer stays closed.
        $prices = OfferState::query('domains_prices')->where('server_id', $id)->where('tld', $command->tld())->get()
            ->filter(static fn ($p) => OfferState::savedCost($p) !== null);
        $snapshot = OfferState::snapshot($b, $offer, OfferState::decode($account->catalogue_defaults), $policy, $prices,
            $servers[$id]->enabled && ($this->ready)($b, $servers[$id], $account), $published);
        $this->projection->write($offer, $snapshot, $now, $native, $published && $retailUpgrade);
        $offer->catalogue_published_revision = $published ? OfferState::json($snapshot->revision) : null;
        // Persist the checked revisions without firing model observers.
        DB::table('domains_tlds')->where('id', $offer->id)->update([
            'catalogue_intent' => $offer->catalogue_intent, 'catalogue_facts' => $offer->catalogue_facts,
            'catalogue_revision' => $offer->catalogue_revision, 'catalogue_published_revision' => $offer->catalogue_published_revision,
            'catalogue_retained_annual_retail' => $offer->catalogue_retained_annual_retail]);
    }

    private function audit(Server $server, Command $command, Revision $before, Revision $after, \DateTimeImmutable $now): void
    {
        StaffSettingsReview::append($server, ['key' => bin2hex(random_bytes(16)), 'actor_id' => $this->actorId,
            'at' => $now->format(DATE_ATOM), 'action' => 'catalogue_'.($command instanceof Edit ? $command->kind : ($command instanceof FeedObserved ? 'feed' : 'facts')),
            'phase' => 'completed', 'tld' => $command->tld(), 'before' => get_object_vars($before), 'after' => get_object_vars($after),
            'input' => $command instanceof Edit ? ['intent' => $command->intent, 'pricing_rule' => $command->rule,
                'retained_annual_retail' => $command->retail, 'seller_actions' => $command->actions]
                : ($command instanceof FeedObserved ? ['observations' => array_map(OfferState::encodeCost(...), $command->costs)]
                    : ['facts' => OfferState::decode(OfferState::encodeFacts($command->facts))])]);
    }

    private function afterCommit(): void
    {
        // Carts keep their lines: checkout re-prices them and drops only a sale staff stopped (CartAdmission::refresh).
        StorefrontCatalogueCache::invalidateAfterCommit();
    }
}
