<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{ PricingRules, PricingRule, StaffSettingsReview, StorefrontCatalogueCache};

/** Dormant single writer. Never registered or called by an active path before checked cutover.
 * Callbacks authorize server-side commands/contexts and check LOCAL readiness, without provider I/O. */
final class Writer implements Catalogue
{
    public function __construct(private readonly Projection $projection, private readonly Clock $clock,
        private readonly \Closure $authorize, private readonly \Closure $ready, private readonly ?int $actorId = null, private readonly ?\Closure $current = null) {}

    /** Queue generation is checked again under the writer locks, before mutations. */
    public function withGuard(\Closure $current): self
    { return new self($this->projection, $this->clock, $this->authorize, $this->ready, $this->actorId, function () use ($current): void {
        if ($this->current) { ($this->current)(); } $current();
    }); }

    public function readOffers(Query $query, Context $context): array
    { return (new OfferReader($this->clock, $this->authorize, $this->ready, $this->current))->readOffers($query, $context); }

    public function apply(Command $command, Revision $expectedRevision): Revision
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Offer writer owns its native-first transaction.'); }
        if (!$command instanceof FeedObserved && !$command instanceof FactsObserved && !$command instanceof Edit) {
            throw new \InvalidArgumentException('Unknown offer command.');
        }
        ($this->authorize)($command, null);
        $binding = $command->binding(); $name = $command->tld();
        $seller = $command instanceof Edit && $command->kind === 'seller';
        $defaultsEdit = $command instanceof Edit && $command->kind === 'defaults';
        $seedQuery = Tld::where('tld', $name);
        if (!$seller) { $seedQuery->where('server_id', $binding->serverId); }
        $seeds = $seedQuery->orderBy('id')->get()->all();
        if (!$seeds && ($command instanceof FeedObserved || $command instanceof FactsObserved)) {
            $seeds = [new Tld(['server_id' => $binding->serverId, 'registrar_key' => $binding->registrarKey,
                'account_fingerprint' => $binding->fingerprint, 'tld' => $name])];
        }
        if (!$seeds || count($seeds) > 500) { throw new \DomainException('Missing or oversized offer group.'); }
        return DB::transaction(function () use ($command, $expectedRevision, $binding, $name, $seller, $defaultsEdit, $seeds): Revision {
            $native = $defaultsEdit ? null : $this->projection->lock($seeds);
            return $this->write($command, $expectedRevision, $seeds, $native);
        });
    }

    /** One global review saves once; each account then publishes independently, matching the legacy F51 result. */
    public function defaultPricing(StaffSettingsReview $review, array $rule, array $fee): array
    {
        if(DB::transactionLevel()!==0||$review->action!=='default_pricing'||$review->serverId!==StaffSettingsReview::ALL_ACCOUNTS||$review->tldId!==null){throw new \DomainException('Business-wide pricing review required.');}
        $targets=DB::transaction(function()use($review,$rule,$fee):array {
            $review->lockActor();$review->lockAccounts();
            if($this->current){($this->current)();}else{UpgradeGate::active(UpgradeGate::generation());}
            $review->check(false,static fn()=>StaffCommands::stamp(StaffSettingsReview::ALL_ACCOUNTS));
            \Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::saveLocked(PricingRules::KEY,$rule);
            \Paymenter\Extensions\Servers\Domains\Services\BusinessSetting::saveLocked(\Paymenter\Extensions\Servers\Domains\Services\CardFee::KEY,$fee);
            $targets=[];$accounts=Account::orderBy('server_id')->lockForUpdate()->get()->keyBy('server_id');
            foreach(Tld::where('catalogue_converted',true)->orderBy('id')->lockForUpdate()->get() as $t){
                $a=$accounts[$t->server_id]??null;
                if($a===null||$t->account_fingerprint!==$a->account_fingerprint||$t->registrar_key!==$a->registrar_key){continue;}
                // Read the saved rule independently of the new fee; unreadable rules keep their TLD warning.
                try{$own=Pricing::own($t,\Paymenter\Extensions\Servers\Domains\Services\CardFee::NONE);
                    if($own!==null){PricingRule::validate([...$own,'card_fee'=>\Paymenter\Extensions\Servers\Domains\Services\CardFee::NONE]);}}
                catch(\DomainException|\InvalidArgumentException){continue;}
                if($own!==null&&$own['mode']!=='markup_fee_percent'){continue;}
                if($own!==null){PricingRule::validate([...$own,'card_fee'=>$fee]);}
                $targets[$t->server_id][]=(int)$t->id;
                // Failed publications must not leave old prices purchasable after the shared rule changes.
                $t->increment('catalogue_revision');
            }
            StorefrontCatalogueCache::invalidateAfterCommit();$review->record();return $targets;
        });
        $summary=['published'=>0,'failed'=>[],'skipped'=>[]];
        foreach(Account::orderBy('server_id')->get() as $a){
            $server=Server::find($a->server_id);$name=$server?->name??'Server #'.$a->server_id;
            if(!$server?->enabled){$summary['skipped'][]=$name;continue;}
            try{foreach($targets[$a->server_id]??[] as $id){
                $t=Tld::findOrFail($id);
                try{
                    $rows=DB::table('domains_prices')->where('server_id',$a->server_id)->where('tld',$t->tld)->where('account_fingerprint',$a->account_fingerprint)->where('currency','USD')->get();
                    if($rows->isEmpty()){throw new \DomainException('Current costs required.');}
                    foreach($rows as $p){if($p->catalogue_observation===null||!OfferState::cost(OfferState::decode($p->catalogue_observation))->fresh($this->clock->now())){throw new \DomainException('Current costs required.');}}
                    $b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($a);
                    $this->apply(Edit::republish($b,$t->tld),OfferState::revision($t,OfferState::decode($a->catalogue_defaults),DB::table('domains_sales_policies')->where('tld',$t->tld)->first()));
                    $summary['published']++;
                }catch(\Throwable $error){
                    $summary['failed'][$name.' #'.$a->server_id][]='.'.$t->tld;
                    if(!$error instanceof \DomainException){\Illuminate\Support\Facades\Log::warning('Domains default pricing publish failed.',['tld'=>$t->tld,'exception_class'=>$error::class]);}
                }
            }}catch(\Throwable $error){
                $summary['failed'][$name.' #'.$a->server_id]??=[];
                \Illuminate\Support\Facades\Log::warning('Domains default pricing account failed.',['server_id'=>(int)$a->server_id,'exception_class'=>$error::class]);
            }
        }
        try{$review->finish('publication_completed',$summary);}
        catch(\Throwable $error){$summary['audit_failed']=true;\Illuminate\Support\Facades\Log::warning('Domains pricing publication outcome audit failed.',['action'=>$review->action,'exception_class'=>$error::class]);}
        return $summary;
    }

    /** One reviewed batch, with the complete shared prefix locked before any row mutation.
     * @return array{rows:array<string,Revision>,failures:array<string,string>} */
    public function reviewed(StaffSettingsReview $review, array $entries, \Closure $stamp): array
    {
        if (DB::transactionLevel() !== 0 || count($entries) < 1 || count($entries) > 25) { throw new \LogicException('Reviewed batches require 1 to 25 commands and an outer transaction.'); }
        $groups = []; $all = [];
        foreach ($entries as [$command, $revision]) {
            if (!$command instanceof Edit || !$revision instanceof Revision) { throw new \InvalidArgumentException('Reviewed edits required.'); }
            ($this->authorize)($command, null);
            $allowed = match ($review->action) {
                'acceptance' => ['defaults', 'override'],
                'override', 'remove_override' => ['override'], 'tld_settings' => ['override', 'retail'], 'seller' => ['seller'],
                'publish' => ['republish'], 'import' => ['sync'], default => [] };
            $scope = Tld::where('server_id', $review->serverId)->where('tld', $command->tld());
            if ($review->tldId !== null) { $scope->whereKey($review->tldId); }
            if (!in_array($command->kind, $allowed, true) || !$scope->exists()
                || $command->kind !== 'seller' && $command->binding()->serverId !== $review->serverId) { throw new \DomainException('Catalogue review scope mismatch.'); }
            $q = Tld::where('tld', $command->tld());
            if ($command->kind !== 'seller') { $q->where('server_id', $command->binding()->serverId); }
            $seeds = $q->orderBy('id')->get()->all();
            if (!$seeds || count($seeds) > 500) { throw new \DomainException('Missing or oversized offer group.'); }
            $groups[] = $seeds;
            if ($command->kind !== 'defaults') { foreach ($seeds as $seed) { $all[$seed->id] = $seed; } }
        }
        return DB::transaction(function () use ($review, $entries, $stamp, $groups, $all): array {
            $review->lockActor();
            $native = $all ? $this->projection->lock(array_values($all)) : null;
            // Legacy reviewed publication takes this same native -> all servers/accounts prefix.
            $review->lockAccounts();
            if ($this->current) { ($this->current)(); } else { UpgradeGate::active(UpgradeGate::generation()); }
            $names = Tld::where('server_id', $review->serverId)->when($review->tldId !== null, fn ($q) => $q->whereKey($review->tldId))->pluck('tld')->all();
            $names = array_values(array_unique([...$names, ...array_map(static fn ($e) => $e[0]->tld(), $entries)])); sort($names, SORT_STRING);
            DB::table('domains_sales_policies')->whereIn('tld', $names)->orderBy('tld')->lockForUpdate()->get();
            Tld::whereIn('tld', $names)->orderBy('id')->lockForUpdate()->get();
            DB::table('domains_prices')->whereIn('tld', $names)->orderBy('id')->lockForUpdate()->get();
            DB::table('settings')->where('settingable_type', (new Server)->getMorphClass())
                ->whereIn('settingable_id', Server::where('extension', 'Domains')->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            $review->check(false, $stamp);
            // Every revision must match, even on rows whose business intent is refused.
            $accepted = $refused = [];
            foreach ($entries as $i => [$command, $expected]) {
                $t = Tld::where('server_id', $command->binding()->serverId)->where('tld', $command->tld())->sole();
                $d = OfferState::decode(Account::where('server_id', $t->server_id)->sole()->catalogue_defaults);
                OfferState::revision($t, $d, DB::table('domains_sales_policies')->where('tld', $t->tld)->first())->requireMatch($expected);
                $reason = $this->intentRefusal($command, $t, $d);
                if ($command->kind === 'override' && in_array('renew', $command->intent->stops, true)
                    && \Paymenter\Extensions\Servers\Domains\Models\Domain::where('server_id', $t->server_id)->where('tld', $t->tld)
                        ->where('account_fingerprint', $t->account_fingerprint)->whereNotNull('reservation_name')->lockForUpdate()->exists()) {
                    $reason = 'Retained holders require annual renewal support.';
                }
                if ($reason !== null) { $refused[$command->tld()] = $reason; } else { $accepted[$i] = true; }
            }
            if (!$accepted) { throw new \DomainException(reset($refused)); }
            $results = [];
            foreach ($entries as $i => [$command, $expected]) {
                if (!isset($accepted[$i])) { continue; }
                $t = Tld::where('server_id', $command->binding()->serverId)->where('tld', $command->tld())->sole();
                $d = OfferState::decode(Account::where('server_id', $t->server_id)->sole()->catalogue_defaults);
                $current = OfferState::revision($t, $d, DB::table('domains_sales_policies')->where('tld', $t->tld)->first());
                $results[$command->tld()] = $this->write($command, $current, $groups[$i], $native, true);
            }
            $review->record('completed', ['rows' => array_keys($results), 'failures' => $refused]);
            return ['rows' => $results, 'failures' => $refused];
        });
    }

    /** Staff may record pending intent; the evaluator still requires independent evidence before selling. */
    private function intentRefusal(Edit $edit, Tld $t, array $defaults): ?string
    {
        if ($edit->kind !== 'override') { return null; }
        $old = OfferState::decode($t->catalogue_intent); $new = $edit->intent;
        $sell = $new->sell ?? ($defaults['defaultSell'] ?? true);
        $longest = $new->longest ?? ($defaults['defaultLongest'] ?? 1);
        $transfer = $new->transfers ?? ($defaults['defaultTransfers'] ?? false);
        $widen = $sell && !($old['sell'] ?? $defaults['defaultSell'] ?? true)
            || $longest > ($old['longest'] ?? $defaults['defaultLongest'] ?? 1)
            || $transfer && !($old['transfers'] ?? $defaults['defaultTransfers'] ?? false)
            || array_diff($old['stops'] ?? [], $new->stops) !== [];
        if ($sell && \Paymenter\Extensions\Servers\Domains\Services\EndingChoices::hidden($t->tld)) { return 'This TLD is hidden.'; }
        if (!$widen) { return null; }
        $facts = OfferState::facts($t->catalogue_facts);
        // Unknown/expired metadata queues the selected sold set after this review. Intent grants no sale.
        if (!$facts || !$facts->fresh($this->clock->now())) { return null; }
        if (!\Paymenter\Extensions\Servers\Domains\Services\RegistryInputs::supported($edit->binding()->registrarKey,$t->tld,$facts->registryFields) || $facts->renewalSafe !== true
            || $facts->requiresNameservers !== false || $facts->autoRenewOnly !== false
            || !in_array(1, $facts->terms['renew'] ?? [], true)
            || $sell && !in_array($longest, $facts->terms['register'] ?? [], true)
            || $transfer && !in_array(1, $facts->terms['transfer'] ?? [], true)) { return 'Explicit verified widening required.'; }
        return null;
    }

    private function write(Command $command, Revision $expectedRevision, array $seeds, ?array $native, bool $reviewed = false): Revision
    {
        $binding = $command->binding(); $name = $command->tld();
        $seller = $command instanceof Edit && $command->kind === 'seller';
        $defaultsEdit = $command instanceof Edit && $command->kind === 'defaults';
            if ($seller && Tld::where('tld', $name)->orderBy('id')->pluck('id')->all() !== array_map(static fn ($s) => $s->id, $seeds)) {
                throw new \DomainException('Seller offer group changed; retry.');
            }
            $ids = array_values(array_unique(array_map(static fn ($s) => (int) $s->server_id, $seeds))); sort($ids, SORT_NUMERIC);
            $servers = $accounts = [];
            foreach ($ids as $id) { $servers[$id] = Server::whereKey($id)->lockForUpdate()->firstOrFail(); }
            foreach ($ids as $id) { $accounts[$id] = Account::where('server_id', $id)->lockForUpdate()->sole(); }
            if (!isset($accounts[$binding->serverId])) { throw new \DomainException('Seller offer missing.'); }
            $binding->requireMatch(AccountBindings::binding($accounts[$binding->serverId]));
            if ($this->current) { ($this->current)(); }
            $policy = DB::table('domains_sales_policies')->where('tld', $name)->lockForUpdate()->first();
            $created = false;
            if (!$seeds[0]->exists && !Tld::where('server_id', $binding->serverId)->where('tld', $name)->exists()) {
                $created = true;
                DB::table('domains_tlds')->insert(['server_id' => $binding->serverId, 'registrar_key' => $binding->registrarKey,
                    'account_fingerprint' => $binding->fingerprint, 'tld' => $name, 'catalogue_created' => true,
                    'catalogue_converted' => true, 'catalogue_intent' => OfferState::json(new Intent)]);
            }
            $offers = Tld::where('tld', $name)->whereIn('server_id', $ids)->select('*')->selectRaw('CAST(catalogue_retained_annual_retail AS CHAR) AS catalogue_retained_annual_retail')->orderBy('id')->lockForUpdate()->get();
            $rows = OfferState::query('domains_prices')->whereIn('server_id', $ids)->where('tld', $name)->orderBy('id')->lockForUpdate()->get();
            $target = $offers->firstWhere('server_id', $binding->serverId) ?? throw new \DomainException('Offer disappeared.');
            $defaults = OfferState::decode($accounts[$binding->serverId]->catalogue_defaults);
            $revision = OfferState::revision($target, $defaults, $policy); $revision->requireMatch($expectedRevision);
            foreach ($offers as $offer) {
                $account = $accounts[$offer->server_id]; $server = $servers[$offer->server_id];
                if ($server->extension !== 'Domains' || !$offer->catalogue_converted
                    || $offer->account_fingerprint !== $account->account_fingerprint || $offer->registrar_key !== $account->registrar_key) {
                    throw new \DomainException('Offer binding or conversion changed.');
                }
            }
            foreach ($rows as $p) {
                if ($p->account_fingerprint !== $accounts[$p->server_id]->account_fingerprint || $p->registrar_key !== $accounts[$p->server_id]->registrar_key) {
                    throw new \DomainException('Price binding changed.');
                }
            }
            // Reauthorize after taking mutexes, before any command mutation.
            ($this->authorize)($command, null); $now = $this->clock->now();
            if ($this->current) { ($this->current)(); }
            if ($defaultsEdit) {
                // Account defaults contain selling intent only. Rule and fee have one global store.
                $defaults = [...get_object_vars($command->intent), '_revision' => $revision->defaults + 1];
                DB::table('domains_accounts')->where('server_id', $binding->serverId)->update(['catalogue_defaults' => OfferState::json($defaults)]);
                if (!$reviewed) { $this->audit($servers[$binding->serverId], $command, $revision, new Revision($revision->offer, $revision->defaults + 1, $revision->selection), $now); }
                // All old publication revisions are now pending. B5 enumerates local republish commands.
                $this->afterCommit();
                return new Revision($revision->offer, $revision->defaults + 1, $revision->selection);
            }
            if ($command instanceof Edit && $command->kind === 'sync') { return $revision; }
            $preserveRetained = $command instanceof Edit && ($seller || $command->kind === 'override'
                && ($command->rule === (OfferState::decode($target->catalogue_intent)['pricing_rule'] ?? null)));
            $changed = $this->mutate($command, $target, $rows->where('server_id', $binding->serverId), $now) || $created;
            if (!$changed) { return $revision; }
            if ($command instanceof Edit && $command->sellerTransfers && $policy && !$policy->transfer_enabled
                && (int) $policy->server_id === $binding->serverId && $policy->account_fingerprint === $binding->fingerprint
                && $policy->registrar_key === $binding->registrarKey && self::transfersSupported($target, $now)) {
                // Transfers On: the same chosen registrar now also takes transfers. Registrations stay as they were.
                DB::table('domains_sales_policies')->where('id', $policy->id)->update(['transfer_enabled' => true, 'revision' => $revision->selection + 1]);
                $policy = DB::table('domains_sales_policies')->where('tld', $name)->first();
            }
            if ($seller) {
                $values = ['server_id' => $command->actions ? $binding->serverId : null,
                    'registrar_key' => $command->actions ? $binding->registrarKey : null,
                    'account_fingerprint' => $command->actions ? $binding->fingerprint : null,
                    'register_enabled' => in_array('register', $command->actions, true),
                    'transfer_enabled' => in_array('transfer', $command->actions, true), 'revision' => $revision->selection + 1];
                if ($policy) { DB::table('domains_sales_policies')->where('id', $policy->id)->update($values); }
                else { DB::table('domains_sales_policies')->insert(['tld' => $name, ...$values]); }
                $policy = DB::table('domains_sales_policies')->where('tld', $name)->first();
            }
            foreach ($seller ? $offers : [$target] as $offer) {
                $id = (int) $offer->server_id; $account = $accounts[$id]; $b = AccountBindings::binding($account);
                $d = OfferState::decode($account->catalogue_defaults);
                $offer->catalogue_revision = (int) $offer->catalogue_revision + 1;
                $annual = $offer->catalogue_retained_annual_retail;
                $published = true;
                if (!$command instanceof Edit || $command->kind !== 'retail') { $published = $this->publishPrices($offer, $now); }
                // Keep unreadable own pricing closed, including when the feed/facts change.
                if (!$published) { $offer->catalogue_published_revision = null; }
                if ($preserveRetained) { $offer->catalogue_retained_annual_retail = $annual; }
                $prices = OfferState::query('domains_prices')->where('server_id', $id)->where('tld', $name)->get();
                $snapshot = OfferState::snapshot($b, $offer, $d, $policy, $prices, $servers[$id]->enabled && ($this->ready)($b, $servers[$id], $account), $published);
                $this->projection->write($offer, $snapshot, $now, $native);
                $offer->catalogue_published_revision = $published ? OfferState::json($snapshot->revision) : null;
                // Raw update: dormant staging does not invoke active legacy model observers.
                DB::table('domains_tlds')->where('id', $offer->id)->update([
                    'catalogue_intent' => $offer->catalogue_intent, 'catalogue_facts' => $offer->catalogue_facts,
                    'catalogue_revision' => $offer->catalogue_revision, 'catalogue_published_revision' => $offer->catalogue_published_revision,
                    'catalogue_retained_annual_retail' => $offer->catalogue_retained_annual_retail]);
            }
            $result = OfferState::revision($target, $defaults, $policy);
            if (!$reviewed) { $this->audit($servers[$binding->serverId], $command, $revision, $result, $now); }
            $this->afterCommit();
            return $result;
    }

    /** Only current registrar terms can say no; unknown terms leave the evaluator to wait for them. */
    private static function transfersSupported(Tld $t, \DateTimeImmutable $now): bool
    {
        $facts = OfferState::facts($t->catalogue_facts);
        return !$facts?->fresh($now) || in_array(1, $facts->terms['transfer'] ?? [], true);
    }

    private function mutate(Command $command, Tld $target, $prices, \DateTimeImmutable $now): bool
    {
        if ($command instanceof Edit) {
            if ($command->kind === 'override') {
                $old = OfferState::decode($target->catalogue_intent);
                foreach (['sell', 'longest', 'transfers', 'stops'] as $key) { $old[$key] = $command->intent->{$key}; }
                $target->catalogue_intent = OfferState::json([...$old, 'pricing_rule' => $command->rule]);
            }
            if ($command->kind === 'retail') { $target->catalogue_retained_annual_retail = $command->retail; }
            return true;
        }
        if ($command instanceof FactsObserved) {
            $old = OfferState::facts($target->catalogue_facts); $new = $command->facts;
            $costs = [];
            foreach ($prices as $p) { if ($p->catalogue_observation !== null) { $costs[] = OfferState::cost(OfferState::decode($p->catalogue_observation)); } }
            $new = (new FeedObserved($command->binding(), $command->tld(), $costs))->facts($new);
            if ($new->observedAt > $now) { throw new \DomainException('Future facts observation.'); }
            if ($old && $old->observedAt > $new->observedAt) { return false; }
            if ($old && $old->observedAt == $new->observedAt) {
                if (!OfferState::equivalent(OfferState::decode(OfferState::encodeFacts($old)), OfferState::decode(OfferState::encodeFacts($new)))) { throw new \DomainException('Conflicting facts observation.'); }
                return false;
            }
            $target->catalogue_facts = OfferState::encodeFacts($new); return true;
        }
        $changed = false;
        foreach ($command->costs as $cost) {
            if ($cost->observedAt > $now) { throw new \DomainException('Future cost observation.'); }
            $p = $prices->first(static fn ($p) => $p->action === $cost->action && (int) $p->term === $cost->term && $p->currency === 'USD');
            $old = $p?->catalogue_observation === null ? null : OfferState::cost(OfferState::decode($p->catalogue_observation));
            if ($old && $old->observedAt > $cost->observedAt) { continue; }
            if ($old && $old->observedAt == $cost->observedAt) {
                $a = OfferState::encodeCost($old); $b = OfferState::encodeCost($cost); unset($a['source_unit'], $b['source_unit']);
                if ($a !== $b) {
                    throw new \DomainException('Conflicting cost observation.');
                }
                continue;
            }
            $values = ['catalogue_observation' => OfferState::json(OfferState::encodeCost($cost))];
            if ($p) { DB::table('domains_prices')->where('id', $p->id)->update($values); }
            else {
                DB::table('domains_prices')->insert([...$values, 'server_id' => $target->server_id, 'registrar_key' => $target->registrar_key,
                    'account_fingerprint' => $target->account_fingerprint, 'tld' => $target->tld, 'action' => $cost->action,
                    'term' => $cost->term, 'currency' => 'USD', 'unit' => 'term_total', 'source' => $cost->source,
                    'observed_at' => $cost->observedAt->format('Y-m-d H:i:s'), 'catalogue_created' => true]);
            }
            $changed = true;
        }
        if ($changed && $target->catalogue_facts !== null) {
            $costs = [];
            foreach (OfferState::query('domains_prices')->where('server_id', $target->server_id)->where('tld', $target->tld)->where('currency', 'USD')->get() as $p) {
                if ($p->catalogue_observation !== null) { $costs[] = OfferState::cost(OfferState::decode($p->catalogue_observation)); }
            }
            $target->catalogue_facts = OfferState::encodeFacts((new FeedObserved($command->binding(), $command->tld(), $costs))->facts(OfferState::facts($target->catalogue_facts)));
        }
        return $changed;
    }

    private function publishPrices(Tld $tld, \DateTimeImmutable $now): bool
    {
        // Shared settings fail the account. Only an unreadable TLD override is isolated.
        $fee = \Paymenter\Extensions\Servers\Domains\Services\CardFee::read();
        $default = (new PricingRules)->defaultRule(false, $fee);
        try { $rule = Pricing::rule($tld, $default, $fee); }
        catch (\DomainException|\InvalidArgumentException $error) {
            if (!Pricing::hasOwn($tld)) { throw $error; }
            return false;
        }
        $freshness = \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::forAccount((int) $tld->server_id);
        $prices = OfferState::query('domains_prices')->where('server_id', $tld->server_id)->where('tld', $tld->tld)->where('currency', 'USD')->orderBy('id')->get();
        foreach ($prices as $p) {
            if ($p->catalogue_observation === null) { continue; }
            $cost = $freshness->cost(OfferState::cost(OfferState::decode($p->catalogue_observation)));
            try { $retail = Retail::quote($cost, $rule); }
            catch (\InvalidArgumentException) { $retail = null; }
            if ($cost->action === 'renew') {
                if ($retail !== null && PricingRule::units($retail, 2) > 0 && $cost->fresh($now)) { $tld->catalogue_retained_annual_retail = $retail; }
                else { $retail = OfferState::money($tld->catalogue_retained_annual_retail, 2); }
            }
            $values = ['wholesale' => $cost->total, 'retail' => $retail, 'unit' => 'term_total', 'source' => $cost->source,
                'observed_at' => $cost->observedAt->format('Y-m-d H:i:s'), 'fresh_until' => $cost->freshUntil->format('Y-m-d H:i:s'),
                'revision' => (int) $p->revision + 1];
            DB::table('domains_prices')->where('id', $p->id)->update($values);
        }
        return true;
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
