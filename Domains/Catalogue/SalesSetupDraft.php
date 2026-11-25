<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Catalogue\Publication\SellerAvailability;

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, CatalogueFreshness, Environment};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Catalogue\Publication\OfferTerms;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\TestCatalogueFacts;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionOffers, CardFee, NativeCatalogue, PricingRules, StaffSettingsReview as Review};

/** A normalized selection. Both the preview and the locked writer rebuild its local evidence. */
final readonly class SalesSetupDraft
{
    public array $suffixes;
    private array $actions;
    private ?int $longest;
    private string $pricing;
    private array $ruleFields;

    public function __construct(public int $serverId, array $suffixes, array $options, private Clock $clock,
        private AccountBindings $bindings, private \Closure $feature)
    {
        if (!array_is_list($suffixes) || !$suffixes || count($suffixes) > 25) {
            throw new \InvalidArgumentException('Review 1 to 25 distinct TLDs.');
        }
        foreach ($suffixes as $name) {
            if (!is_string($name)) { throw new \InvalidArgumentException('Invalid domain ending.'); }
            CanonicalName::parse('example.'.$name, [$name]);
        }
        if (count(array_unique($suffixes)) !== count($suffixes)) { throw new \InvalidArgumentException('Duplicate TLD.'); }
        sort($suffixes, SORT_STRING);
        $this->suffixes = $suffixes;
        if (array_diff(array_keys($options), ['register','transfer','longest','pricing','rule'])
            || !is_bool($options['register'] ?? null) || !is_bool($options['transfer'] ?? null)
            || !$options['register'] && !$options['transfer']
            || !in_array($options['pricing'] ?? null, ['keep','default','custom'], true)
            || isset($options['longest']) && (!is_int($options['longest']) || $options['longest'] < 1 || $options['longest'] > 10)) {
            throw new \InvalidArgumentException('Invalid sales setup options.');
        }
        $this->actions = array_values(array_filter(['register','transfer'], static fn ($a) => $options[$a]));
        $this->pricing = $options['pricing'];
        $this->longest = $options['longest'] ?? null;
        $fields = $options['rule'] ?? [];
        if (!is_array($fields) || $this->pricing !== 'custom' && $fields !== []
            || array_diff(array_keys($fields), ['mode','markup','round_up','fixed_register','fixed_renew','fixed_transfer'])) {
            throw new \InvalidArgumentException('Invalid pricing fields.');
        }
        $this->ruleFields = $fields;
    }

    private function authorize(): void
    {
        $actions = ['seller'];
        if ($this->longest !== null) { $actions[] = 'acceptance'; }
        if ($this->pricing !== 'keep') {
            $actions[] = $this->pricing === 'default' ? 'remove_override' : 'override';
            $actions[] = 'publish';
        }
        foreach ([Staff::POLICY, ...array_map(Review::permission(...), $actions)] as $right) {
            if (!Staff::can($right)) { throw new \DomainException('Current staff permissions required.'); }
        }
    }

    public function plan(): array
    {
        $this->authorize();
        $account = Account::where('server_id', $this->serverId)->sole();
        $server = Server::findOrFail($this->serverId);
        $staffStamp = StaffCommands::stamp($this->serverId);
        $fee = CardFee::read();
        $rule = $this->pricing === 'custom' ? StaffCommands::rule($this->ruleFields,
            ($this->ruleFields['mode'] ?? null) === 'markup_fee_percent' ? $fee : CardFee::NONE) : null;
        $groups = Tld::whereIn('tld', $this->suffixes)->orderBy('id')->get();
        $native = app(NativeCatalogue::class)->displayBatch($groups->all());
        $prices = OfferState::query('domains_prices')->whereIn('tld', $this->suffixes)->orderBy('id')->get();
        $policies = DB::table('domains_sales_policies')->whereIn('tld', $this->suffixes)->orderBy('tld')->get()->keyBy('tld');
        $evidence = [$staffStamp, $this->serverId, $this->suffixes, $this->actions, $this->longest, $this->pricing, $rule,
            $groups->map(static fn ($t) => $t->getRawOriginal())->all(), $prices->all(), $policies->all()];
        $defaultRule = (new PricingRules)->defaultRule(false, $fee);
        $showCosts = Staff::can(Staff::COSTS);
        $now = $this->clock->now();
        $rows = $entries = $readiness = [];
        $accounts = Account::whereIn('server_id', $groups->pluck('server_id')->push($this->serverId))->get()->keyBy('server_id');
        $servers = Server::whereIn('id', $accounts->keys())->get()->keyBy('id');
        $freshness = CatalogueFreshness::forAccounts($accounts->keys()->all(), omitInvalid: true);
        $bindings = $accountErrors = $credentials = [];
        foreach ($accounts as $id => $a) {
            try {
                $bindings[$id] = AccountBindings::binding($a);
            } catch (\DomainException|\InvalidArgumentException|\TypeError|\ValueError|\Illuminate\Contracts\Encryption\DecryptException) {
                $accountErrors[$id] = 'Registrar account identity unreadable.';
                continue;
            }
            if (!isset($freshness[$id])) {
                $accountErrors[$id] = 'Registrar price freshness unreadable.';
                continue;
            }
            $b = $bindings[$id];
            $credential = null;
            try {
                $credential = $this->bindings->offerCredentialRevision($b);
                $credentials[$id] = $credential;
                $this->bindings->requireOfferAccount($b, $servers[$id], $a);
                $ready = true;
            } catch (\DomainException) { $ready = false; }
            foreach (['register','renew','transfer'] as $action) {
                $readiness[$id][$action] = $ready && ($this->feature)($b, $action);
            }
            $evidence[] = [$id, $b->fingerprint, $credential, $readiness[$id], $freshness[$id]->hours];
        }
        $binding = $bindings[$this->serverId] ?? null;
        $groups = $groups->groupBy('tld');
        $prices = $prices->groupBy(fn ($p) => $p->server_id.':'.$p->tld);
        foreach ($this->suffixes as $name) {
            $group = $groups[$name] ?? collect();
            $t = $group->firstWhere('server_id', $this->serverId);
            $policy = $policies[$name] ?? null;
            $oldId = isset($policy->server_id) ? (int) $policy->server_id : null;
            $old = $group->firstWhere('server_id', $oldId);
            $row = ['server_id' => $this->serverId, 'tld_id' => $t?->id, 'seller_before' => $oldId,
                'actions_before' => array_values(array_filter(['register','transfer'], static fn ($a) => $policy?->{$a.'_enabled'})),
                'orders_paused' => !(bool) $account->processing_enabled,
                'actions_after' => $this->actions, 'status' => 'blocked', 'reason' => null, 'prices' => [],
                'previous_sales' => [], 'retained_before' => $t?->catalogue_retained_annual_retail,
                'retained_after' => $t?->catalogue_retained_annual_retail];
            if ($old && isset($readiness[$oldId])) {
                try {
                    $oldSnapshot = $this->snapshot($bindings[$oldId], $old, $accounts[$oldId], $policy,
                        $prices[$oldId.':'.$name] ?? collect(), $freshness[$oldId]);
                    foreach ($this->terms($oldSnapshot, $readiness[$oldId], $now, $native[$old->id] ?? []) as $key => $term) {
                        if ($key !== 'renew:1' && $term['available']) { $row['previous_sales'][$key] = $term['retail']; }
                    }
                } catch (\Throwable) {
                    // The public catalogue withdraws this whole offer when its saved evidence is unreadable.
                    $row['previous_sales'] = [];
                }
            }
            try {
                if (!$server->enabled || $server->extension !== 'Domains') { throw new \DomainException('Seller account off.'); }
                if (isset($accountErrors[$this->serverId])) {
                    throw new \DomainException($accountErrors[$this->serverId]);
                }
                $availability = SellerAvailability::state($account, $t);
                if ($availability !== SellerAvailability::READY) {
                    throw new \DomainException($availability === SellerAvailability::UNREADABLE ? 'Seller price unreadable.' : 'Seller price missing.');
                }
                $source = $prices[$this->serverId.':'.$name] ?? collect();
                $before = $this->snapshot($binding, $t, $account, $policy, $source, $freshness[$this->serverId]);
                $evidence[] = [$name, $before->facts?->fresh($now), array_map(static fn ($c) => $c->fresh($now), $before->costs)];
                if ($before->hidden) { throw new \DomainException('This TLD is hidden.'); }
                $intent = $before->intent->forSeller($this->actions, get_object_vars($before->intent));
                if ($this->longest !== null) { $intent = new Intent(...[...get_object_vars($intent), 'longest' => $this->longest]); }
                $facts = $before->facts;
                $block = OfferTerms::termsBlock($facts, $binding->registrarKey, $name, in_array('register', $this->actions, true),
                    $intent->longest ?? $intent->defaultLongest, in_array('transfer', $this->actions, true), $now);
                $row['reason'] = match ($block) {
                    null => null,
                    'unsupported_facts' => OfferTerms::unsupportedCause($facts, $binding->registrarKey, $name),
                    default => 'Requested registrar terms are unsupported.',
                };
                [$row, $command] = $this->propose($row, $t, $before, $intent, $block, $rule, $defaultRule, $fee, $showCosts,
                    $readiness[$this->serverId], $now, $native[$t->id] ?? []);
                if ($block === null && $binding->registrarKey === 'namesilo' && $binding->environment === Environment::Test
                    && isset($credentials[$this->serverId])
                    && TestCatalogueFacts::hasEvidence($binding, $credentials[$this->serverId], $name, $this->clock) === false) {
                    $row['facts_state'] = 'waiting_for_evidence';
                }
                if ($command || $row['status'] === 'unchanged' && $this->pricing !== 'keep') {
                    // Seller changes also republish this suffix's other accounts. Price-only edits touch the target.
                    $publishing = $command?->publishesSeller() ? $group : [$t];
                    foreach ($publishing as $offer) {
                        $id = (int) $offer->server_id;
                        $source = $prices[$id.':'.$name] ?? collect();
                        $row['problem_account'] = $id;
                        OfferTerms::validateGroup([$offer], $source, $servers, $accounts);
                        if (isset($accountErrors[$id])) {
                            throw new \DomainException($accountErrors[$id]);
                        }
                        $publishes = $row['prices'];
                        if ($id !== $this->serverId) {
                            $publishes = $this->outgoingPrices($offer, $accounts[$id], $source, $policy,
                                $freshness[$id], $defaultRule, $fee, $now);
                        }
                        $evidence[] = Projection::publicationEvidence($offer, $native[$offer->id] ?? null, $publishes);
                        unset($row['problem_account']);
                    }
                }
                if ($command) { $entries[$name] = [$command, $before->revision]; }
            } catch (\DomainException|\InvalidArgumentException|\JsonException|\TypeError|\ValueError $error) {
                $row['status'] = 'blocked';
                $row['reason'] ??= $error instanceof \DomainException || $error instanceof \InvalidArgumentException
                    ? $error->getMessage() : 'Saved TLD settings cannot be read.';
                $row['retained_after'] = $row['retained_before'];
                foreach ($row['prices'] as &$term) {
                    $term['available'] = false;
                    $term['reason'] ??= 'publication_pending';
                }
                unset($term);
            }
            $rows[$name] = $row;
        }
        return ['rows' => $rows, 'stamp' => hash('sha256', OfferState::json([$evidence, $rows])),
            'entries' => $entries, 'binding' => $binding];
    }

    private function snapshot(AccountBinding $binding, Tld $t, Account $account, ?object $policy, Collection $prices, CatalogueFreshness $freshness): Snapshot
    {
        foreach ([$t, ...$prices] as $p) {
            if ($p->account_fingerprint !== $binding->fingerprint || $p->registrar_key !== $binding->registrarKey) {
                throw new \DomainException('Offer or price binding changed.');
            }
        }
        return OfferState::snapshot($binding, $t, self::defaults($account, $t, $policy), $policy, $prices, true, freshness: $freshness);
    }

    private static function defaults(Account $account, Tld $offer, ?object $policy): array
    {
        try {
            $defaults = OfferState::decode($account->catalogue_defaults);
            OfferState::intent($offer, $defaults);
            OfferState::revision($offer, $defaults, $policy);
            return $defaults;
        } catch (\JsonException|\DomainException|\InvalidArgumentException|\ValueError|\TypeError) {
            throw new \DomainException('Registrar sales settings unreadable.');
        }
    }

    /** A displaced seller writes a native price only for a retained plan during a rule upgrade. */
    private function outgoingPrices(Tld $offer, Account $account, Collection $source, ?object $policy,
        CatalogueFreshness $freshness, array $defaultRule, array $fee, \DateTimeImmutable $now): array
    {
        self::defaults($account, $offer, $policy);
        if ($offer->catalogue_published_revision === null || OfferState::currentPublication($offer->catalogue_published_revision)) {
            return [];
        }
        $rule = Pricing::publicationRule($offer, $defaultRule, $fee);
        if ($rule === null) { return []; }
        $costs = [];
        foreach ($source as $price) {
            if (($cost = OfferState::savedCost($price)) !== null && $price->currency === 'USD') {
                $costs[$cost->key()] = $freshness->cost($cost);
            }
        }
        $terms = [];
        foreach ($costs as $key => $cost) {
            [$retail] = Retail::publication($cost, $rule, $costs, $now, $offer->catalogue_retained_annual_retail);
            $terms[$key] = ['after' => $retail, 'available' => false];
        }
        return $terms;
    }

    private function propose(array $row, Tld $t, Snapshot $before, Intent $intent, ?string $block, ?array $rule, array $defaultRule, array $fee,
        bool $showCosts, array $ready, \DateTimeImmutable $now, array $native): array
    {
        $binding = $before->feed->binding();
        $oldIntent = $before->intent;
        $changedSeller = !$before->seller || $before->sellerActions !== $this->actions;
        $changedRule = false;
        $proposed = clone $t;
        if ($this->pricing !== 'keep') {
            try {
                $changedRule = Pricing::hasOwn($t) !== ($rule !== null)
                    || $rule !== null && !OfferState::equivalent(Pricing::rule($t, $defaultRule, $fee), $rule);
            }
            catch (\DomainException|\InvalidArgumentException|\TypeError) { $changedRule = true; }
            $proposed->catalogue_intent = OfferState::json([...OfferState::decode($t->catalogue_intent), 'pricing_rule' => $rule]);
        }
        $effective = Pricing::rule($proposed, $defaultRule, $fee);
        $row['facts_state'] = $before->facts === null ? 'unknown' : ($before->facts->fresh($now) ? 'current' : 'stale');
        $row['pricing_after'] = Pricing::hasOwn($proposed) ? 'custom' : 'default';
        $row['pricing_before'] = Pricing::hasOwn($t) ? 'custom' : 'default';
        $row['longest_after'] = $intent->longest ?? $intent->defaultLongest;
        $row['longest_before'] = $oldIntent->longest ?? $oldIntent->defaultLongest;
        $annual = $before->retainedAnnualRetail;
        $quotes = [];
        foreach ($before->costs as $key => $cost) {
            [$retail, $annual] = Retail::publication($cost, $effective, $before->costs, $now, $annual);
            if ($retail !== null) { $quotes[$key] = $retail; }
        }
        // Explicit price publication can still be due when the stored pricing rule already matches.
        $pendingPrice = $this->pricing !== 'keep' && (!$before->publishedRevision?->matches($before->revision)
            || !OfferState::equivalent($before->retail, $quotes));
        $needsWrite = $changedSeller || $intent != $oldIntent || $changedRule || $pendingPrice;
        $row['retained_after'] = $block === null && $changedRule ? $annual : $before->retainedAnnualRetail;
        if (!$needsWrite && $block === null && $this->pricing !== 'keep') {
            $existing = $this->terms($before, $ready, $now, $native);
            $needsWrite = in_array('native_price_mismatch', array_column($existing, 'reason'), true);
        }
        $projected = $needsWrite || $block !== null;
        $after = $projected ? new Snapshot($before->feed, $before->facts, $intent, $before->revision, $before->revision,
            $quotes, $binding, $this->actions, true, $before->hidden, $row['retained_after']) : $before;
        $terms = !$projected && isset($existing) ? $existing : $this->terms($after, $ready, $now, $projected ? null : $native, $block);
        foreach ($terms as $key => $term) {
            $row['prices'][$key] = ['before' => $before->retail[$key] ?? null, 'after' => $term['retail'],
                'available' => $term['available'], 'reason' => $term['reason']];
            if ($showCosts) {
                $cost = isset($after->costs[$key]) ? Retail::cost($after->costs[$key], $after->costs) : null;
                $row['prices'][$key]['cost'] = $cost;
                $row['prices'][$key]['profit'] = $cost === null || $term['retail'] === null ? null : CardFee::profit($term['retail'], $cost, $fee);
            }
        }
        if ($block !== null) { return [$row, null]; }
        $row['status'] = $needsWrite ? 'ready' : 'unchanged';
        $command = $needsWrite ? Edit::setup($binding, $t->tld, $intent, $this->actions, $this->pricing, $rule, $changedSeller, $changedRule) : null;
        if ($command && ($reason = OfferTerms::intentRefusal($command, $t, get_object_vars($oldIntent), $now)) !== null) {
            throw new \DomainException($reason);
        }
        return [$row, $command];
    }

    /** Same action and annual renewal gates as acquisition. A prospective publication supplies its own native plans. */
    private function terms(Snapshot $s, array $ready, \DateTimeImmutable $now, ?array $native, ?string $block = null): array
    {
        $terms = [];
        foreach (['renew','register','transfer'] as $action) {
            foreach ($action === 'register' ? range(1, 10) : [1] as $term) {
                $key = $action.':'.$term;
                $q = new Query($s->feed->binding(), [$s->feed->tld()], $action, $term);
                $retail = $s->retail[$key] ?? null;
                $reason = $block ?? ($ready[$action] ? (new Evaluator)->evaluate($s, $q, Context::Staff, $now)->reason : 'account_unavailable');
                if ($action !== 'renew') {
                    $reason ??= $terms['renew:1']['reason'];
                    if ($native !== null && $reason === null) {
                        $product = $native[$action]['product'] ?? null;
                        $plan = $native[$action]['plans'][$term] ?? null;
                        if (AcquisitionOffers::nativePriceMismatch($product, $plan, $retail) !== null) {
                            $reason = $plan !== null && $product->stock === null && $plan->prices->count() <= 1
                                ? 'native_price_mismatch' : 'native_mapping_unavailable';
                            if ($reason === 'native_price_mismatch' && ($usd = $plan->prices->first())) {
                                try { Projection::priceDiffers($usd, $retail); }
                                catch (\DomainException) { $reason = 'native_mapping_unavailable'; }
                            }
                        }
                    }
                }
                $terms[$key] = ['retail' => $retail, 'available' => $reason === null, 'reason' => $reason];
            }
        }
        return $terms;
    }

    /** Reread published settings, amounts and native availability before the review, audit and prices commit together. */
    public function receipt(Review $review, array $plan, array $saved, string &$stamp): array
    {
        $actual = $this->plan();
        $stamp = $actual['stamp'];
        $failures = $unchanged = $changes = $outgoing = [];
        $outcomes = $plan['rows'];
        foreach ($plan['rows'] as $name => $row) {
            if ($row['status'] === 'unchanged') { $unchanged[] = $name; continue; }
            if ($row['status'] === 'blocked') { $failures[$name] = $row['reason']; continue; }
            $published = $actual['rows'][$name];
            if ($published['status'] !== 'unchanged' || $row['retained_after'] !== $published['retained_before']) {
                throw new \DomainException('Published sales setup differs from the review.');
            }
            $changes[$name] = array_intersect_key($row, array_flip(['seller_before','server_id','actions_before','actions_after',
                'longest_before','longest_after','pricing_before','pricing_after','retained_before','retained_after']));
            foreach ($row['prices'] as $key => $term) {
                if ($published['prices'][$key] !== [...$term, 'before' => $term['after']]) {
                    throw new \DomainException('Published term or price differs from the review.');
                }
                if ($term['before'] !== $term['after']) {
                    $changes[$name]['prices'][$key] = ['before' => $term['before'], 'after' => $term['after']];
                }
            }
            if ($row['seller_before'] !== null && $row['seller_before'] !== $this->serverId) {
                $outgoing[$row['seller_before']][] = $name;
            }
            $outcomes[$name]['status'] = 'saved';
        }
        $review->input(['rows' => $changes]);
        $review->record('completed', ['rows' => $saved, 'failures' => $failures, 'unchanged' => $unchanged], $outgoing);
        return ['rows' => $saved, 'failures' => $failures, 'unchanged' => $unchanged,
            'outcomes' => $outcomes, 'binding' => $plan['binding']];
    }
}
