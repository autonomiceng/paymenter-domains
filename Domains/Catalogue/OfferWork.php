<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, CardFee, EnomTestCallLimit, PricingRules, ReadDeferred, ReadFailure};

/** Dormant account worker on the retained queue. No container binding, job or caller switch.
 * $feed supplies bound adapters using existing admitted transports (including daily wire caps).
 * $allowance supplies OUR daily headroom for this origin, preserving capacity for holding reads. */
final class OfferWork
{
    public const BATCH = 25;
    private const LEASE = 300;
    public function __construct(private readonly Writer $writer, private readonly Clock $clock,
        private readonly AccountReadBudget $minute, private readonly \Closure $feed, private readonly \Closure $allowance,
        private readonly ?\Closure $credentials = null, private readonly ?int $generation = null,
        private readonly ?\Closure $guard = null) {
        if (($generation === null) !== ($guard === null)) { throw new \LogicException('Generation and guard must be composed together.'); }
    }

    /** Manual and automatic triggers enqueue the same account job. No provider I/O. */
    public function sync(AccountBinding $binding, RequestOrigin $origin = RequestOrigin::Interactive, ?int $expectedGeneration = null): void
    {
        $this->editQueue($binding, function ($q, $row) use ($origin, $expectedGeneration): void {
            if ($expectedGeneration !== null && $expectedGeneration !== UpgradeGate::generation()) { throw new \DomainException('Queued offer generation changed.'); }
            $work = OfferState::decode($row->offer_work);
            $claimed = (int) $row->offer_lease > $this->at();
            if ($claimed && ($work['feed_claim'] ?? null) === $row->offer_claim) { return; }
            if (($work['tries'] ?? 0) > 0 || (!empty($work['pending']) && $origin !== RequestOrigin::Interactive)) { return; }
            $work['feed_due'] = $origin === RequestOrigin::Interactive ? $this->at() : ($work['feed_due'] ?? $this->at());
            if (!isset($work['origin']) || $origin === RequestOrigin::Interactive) { $work['origin'] = $origin->value; }
            if ($claimed) { $work['feed_hint'] = true; }
            $q->update(['offer_work' => OfferState::json($work)]);
        });
    }

    /** Fee/default changes wake bounded local republication without scheduling provider reads. */
    public function wake(AccountBinding $binding): void
    { $this->editQueue($binding, static function (): void {}); }

    /** Search/read hints only for a discovered offer. Repeated hints preserve failure backoff. */
    public function requestFacts(AccountBinding $binding, string $tld, bool $soldOnly = false): void
    {
        new Query($binding, [$tld], 'register');
        $this->editQueue($binding, function ($q, $row) use ($binding, $tld, $soldOnly): void {
            $t = Tld::where('server_id', $binding->serverId)->where('tld', $tld)->firstOrFail();
            $this->offerBinding($binding, $t); $work = OfferState::decode($row->offer_work);
            $facts = OfferState::facts($t->catalogue_facts);
            $current = $facts && TermSupport::currentFacts($binding, $tld, $facts);
            $authority = hash('sha256', OfferState::json(TermSupport::evidence($binding, $tld)));
            $state = $work['facts'][$tld] ?? null;
            if ($state !== null && ($current || ($state['authority'] ?? null) === $authority)) { return; }
            if ($state === null && count($work['facts'] ?? []) >= 5000) { throw new \DomainException('Offer facts queue is full.'); }
            // Wake once per authority change. Subsequent hints keep provider failure backoff intact.
            $work['facts'][$tld] = ['sold_only' => $state['sold_only'] ?? $soldOnly, 'authority' => $authority,
                'due_at' => $current && $facts->fresh($this->clock->now()) ? $facts->freshUntil->getTimestamp() : $this->at()];
            $q->update(['offer_work' => OfferState::json($work)]);
        });
    }

    /** Production hints are restricted to the selected sold set, never every discovered ending. */
    public function requestSoldFacts(AccountBinding $binding, string $tld): void
    {
        if (!$this->sold($binding, $tld)) { return; }
        $this->requestFacts($binding, $tld, true);
    }

    private function sold(AccountBinding $binding, string $tld): bool
    {
        $t = Tld::where('server_id', $binding->serverId)->where('tld', $tld)->sole();
        $this->offerBinding($binding, $t);
        $intent = OfferState::decode($t->catalogue_intent);
        $defaults = OfferState::decode($this->account($binding)->catalogue_defaults);
        if (!($intent['sell'] ?? $defaults['defaultSell'] ?? true) || in_array('renew', $intent['stops'] ?? [], true)) { return false; }
        $policy = DB::table('domains_sales_policies')->where('server_id', $binding->serverId)->where('tld', $tld)
            ->where('account_fingerprint', $binding->fingerprint)->where('registrar_key', $binding->registrarKey)->first();
        if (!$policy || !($policy->register_enabled && !in_array('register', $intent['stops'] ?? [], true)
            || $policy->transfer_enabled && ($intent['transfers'] ?? $defaults['defaultTransfers'] ?? false)
                && !in_array('transfer', $intent['stops'] ?? [], true))) { return false; }
        return true;
    }

    /** Safe local status; no cost, credentials, raw error or acceptance-list copies. */
    public function status(AccountBinding $binding, ?string $tld = null): array
    {
        $this->fence($binding); $this->account($binding);
        $row = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->where('account_fingerprint', $binding->fingerprint)->first();
        $work = OfferState::decode($row?->offer_work);
        if ($this->generation !== null && ($work['upgrade_generation'] ?? null) !== $this->generation) { return ['state' => 'queued']; }
        $failure = empty($work['local_failure']) ? [] : ['state' => 'retrying', 'failure' => $work['local_failure'],
            'local_failure' => true, 'failed_at' => $work['local_failed_at'], 'due_at' => null];
        if ($tld !== null) {
            $t = Tld::where('server_id', $binding->serverId)->where('tld', $tld)->firstOrFail(); $this->offerBinding($binding, $t);
            if ($failure) { return $failure; }
            $state = $work['facts'][$tld] ?? []; $facts = OfferState::facts($t->catalogue_facts);
            return [...$state, 'state' => $facts && $facts->fresh($this->clock->now()) && TermSupport::currentFacts($binding, $tld, $facts) ? 'ready' : (!$state ? 'unknown' : (!empty($state['unsupported']) ? 'unsupported' : (!empty($state['failure']) ? 'retrying' : 'queued'))),
                'due_at' => $state['due_at'] ?? null];
        }
        if (!$row || $row->offer_work === null) { return ['state' => 'idle']; }
        return ['state' => (int) $row->offer_lease > $this->at() ? 'running' : (!empty($work['pending']) || ($work['feed_due'] ?? PHP_INT_MAX) <= $this->at() ? 'queued' : (!empty($row->offer_feed_failure) ? 'retrying' : 'ready')),
            'tried_at' => $row->offer_feed_tried_at, 'succeeded_at' => $row->offer_feed_succeeded_at,
            'failed_at' => $row->offer_feed_failed_at, 'failure' => $row->offer_feed_failure,
            'due_at' => $work['feed_due'] ?? null, 'remaining' => count($work['pending'] ?? []), ...$failure];
    }

    /** Read-only completion proof for retained work, independent of a later authority change.
     * This does not assert current saleability or authorize another provider read. Callers must
     * separately verify the retained publication and fence their initiating identity. */
    public function settled(AccountBinding $binding, \DateTimeImmutable $after, array $endings): bool
    {
        $this->fence($binding); $this->account($binding);
        $row = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->first();
        if (!$row || $row->account_fingerprint !== $binding->fingerprint) { return false; }
        $work = OfferState::decode($row->offer_work); $now = $this->clock->now();
        if ($row->offer_claim !== null || (int) $row->offer_lease > $this->at()
            || (int) $row->offer_feed_succeeded_at <= $after->getTimestamp()
            || (int) $row->offer_feed_succeeded_at > $this->at()
            || (int) $row->offer_feed_tried_at > (int) $row->offer_feed_succeeded_at
            || $row->offer_feed_failure !== null || !empty($work['pending']) || !empty($work['local_failure'])
            || !empty($work['tries']) || !empty($work['feed_hint']) || ($work['feed_due'] ?? 0) <= $this->at()
            || $this->generation !== null && ($work['upgrade_generation'] ?? null) !== $this->generation
            || $this->credentials && ($work['credential_revision'] ?? null) !== ($this->credentials)($binding)) { return false; }
        if (!$endings) { return false; }
        foreach ($endings as $ending) {
            $t = Tld::where('server_id', $binding->serverId)->where('tld', $ending)->first();
            if (!$t) { return false; }
            $this->offerBinding($binding, $t);
            $facts = OfferState::facts($t->catalogue_facts); $state = $work['facts'][$ending] ?? [];
            if (!$facts || !$facts->fresh($now) || $facts->observedAt > $now
                || empty($state['succeeded_at']) || $state['succeeded_at'] > $this->at() || $state['succeeded_at'] < $facts->observedAt->getTimestamp()
                || ($state['tried_at'] ?? 0) > $state['succeeded_at'] || !empty($state['failure'])
                || !empty($state['tries']) || !empty($state['unsupported']) || ($state['due_at'] ?? 0) <= $this->at()) { return false; }
        }
        return true;
    }

    /** Fair bounded rotation, including accounts with only lazy facts/local republication. */
    public function tick(int $accounts = 8): array
    {
        $this->outside();
        if ($this->generation !== null) { throw new \LogicException('Composed work requires the shared scheduler.'); }
        if ($accounts < 1 || $accounts > 32) { throw new \InvalidArgumentException('Invalid account batch.'); }
        $ids = DB::transaction(function () use ($accounts): array {
            $q = DB::table('domains_observation_queues')->whereNotNull('offer_work');
            $rows = (clone $q)->orderBy('offer_turn')->orderBy('server_id')->limit($accounts)->lockForUpdate()->get();
            $turn = (int) $q->max('offer_turn');
            foreach ($rows as $r) { (clone $q)->where('id', $r->id)->update(['offer_turn' => ++$turn]); }
            return $rows->pluck('server_id')->all();
        });
        $out = [];
        foreach ($ids as $id) {
            try { $out[$id] = $this->run((int) $id); }
            catch (\Throwable $error) { $out[$id] = ReadFailure::code($error); }
        }
        return $out;
    }

    public function accountTurn(int $server): string { $this->outside(); return $this->run($server); }

    private function run(int $server): string
    {
        $account = Account::where('server_id', $server)->sole(); $b = AccountBindings::binding($account);
        $credential = $this->credentials ? ($this->credentials)($b) : null;
        $job = null;
        $this->editQueue($b, function ($q, $row) use (&$job): void {
            if ((int) $row->offer_lease > $this->at()) { return; }
            $job = $row; $job->offer_claim = bin2hex(random_bytes(16)); $job->offer_feed_run++;
            $q->update(['offer_claim' => $job->offer_claim, 'offer_lease' => $this->at() + self::LEASE, 'offer_feed_run' => $job->offer_feed_run]);
        });
        if (!$job) { return 'busy'; }
        $job->credentialRevision = $credential;
        $guard = function () use ($b, $job): void { $this->current($b, $job); };
        $writer = $this->writer->withGuard($guard); $work = OfferState::decode($job->offer_work);
        try {
            if ($this->credentials && ($work['credential_revision'] ?? null) !== $credential
                || $this->generation !== null && ($work['upgrade_generation'] ?? null) !== $this->generation) {
                // A durable buffer belongs to the credentials that fetched it, even after restart.
                $work['pending'] = []; $work['feed_due'] = $this->at(); $work['tries'] = 0;
                $work['credential_revision'] = $credential; $work['upgrade_generation'] = $this->generation;
            }
            $this->republish($writer, $b, $work);
            // Local republication can wake an existing month-long facts entry after authority changes.
            $work['facts'] = OfferState::decode(DB::table('domains_observation_queues')->where('server_id', $b->serverId)->value('offer_work'))['facts'] ?? [];
            $t = $this->nextFacts($work);
            if (!empty($work['pending'])) {
                if ($t) { $this->facts($writer, $b, $t, $work, $guard); }
                $this->drain($writer, $b, $work);
            } else {
                $due = ($work['feed_due'] ?? PHP_INT_MAX) <= $this->at();
                if ($t && (!$due || ($work['last'] ?? '') === 'feed')) {
                    $work['last'] = 'facts'; $this->facts($writer, $b, $t, $work, $guard);
                } elseif ($due) {
                    $this->beginFeed($b, $job, $work);
                    $work['last'] = 'feed'; $this->prices($b, $job, $work, $guard);
                    // Durable buffer precedes per-offer writes; a crash can resume without rereading.
                    $this->save($b, $job, ['offer_work' => OfferState::json($work)]);
                    $this->drain($writer, $b, $work);
                }
            }
            unset($work['local_failure'], $work['local_failed_at']);
            $this->save($b, $job, ['offer_work' => OfferState::json($work), 'offer_claim' => null, 'offer_lease' => 0]);
            return 'worked';
        } catch (\Throwable $error) {
            // Keep a durable pending buffer on local writer failure; never repeat its provider feed.
            $work['local_failure'] = ReadFailure::code($error); $work['local_failed_at'] = $this->at();
            try {
                $this->save($b, $job, ['offer_work' => OfferState::json($work), 'offer_claim' => null, 'offer_lease' => 0]);
            } catch (\Throwable) {
                // Superseded work releases only the claim/run it owns; no buffer or success changes.
                DB::table('domains_observation_queues')->where('server_id', $b->serverId)->where('account_fingerprint', $b->fingerprint)
                    ->where('offer_claim', $job->offer_claim)->where('offer_feed_run', $job->offer_feed_run)
                    ->update(['offer_claim' => null, 'offer_lease' => 0]);
            }
            throw $error;
        }
    }

    private function beginFeed(AccountBinding $b, object $job, array &$work): void
    {
        $this->editQueue($b, function ($q, $row) use ($b, $job, &$work): void {
            $this->current($b, $job); $stored = OfferState::decode($row->offer_work);
            // This feed satisfies hints received during earlier local work in the same turn.
            if (!empty($stored['feed_hint'])) { $work['origin'] = $stored['origin']; }
            $stored['feed_claim'] = $work['feed_claim'] = $job->offer_claim;
            unset($stored['feed_hint'], $work['feed_hint']);
            $q->update(['offer_work' => OfferState::json($stored)]);
        });
    }

    private function prices(AccountBinding $b, object $job, array &$work, \Closure $guard): void
    {
        $origin = RequestOrigin::from($work['origin'] ?? RequestOrigin::Background->value);
        try {
            $feed = ($this->feed)($b); $calls = $feed->priceReads();
            $rows = $this->read($b, $origin, $calls, function ($context) use ($feed, $guard, $b, $job): array {
                $this->save($b, $job, ['offer_feed_tried_at' => $this->at()]);
                return FeedRun::prices($feed, $context, $guard);
            }, $guard);
            if (count($rows) > 5000) { throw new \DomainException('Oversized offer feed.'); }
            $pending = [];
            foreach ($rows as $r) { $pending[] = ['tld' => $r->tld(), 'costs' => array_values(array_map(OfferState::encodeCost(...), $r->costs))]; }
            if (strlen(OfferState::json($pending)) > 8 * 1024 * 1024) { throw new \DomainException('Oversized offer feed.'); }
            $work['pending'] = $pending; $work['feed_due'] = $this->at() + 86400; $work['tries'] = 0;
            $work['origin'] = RequestOrigin::Background->value;
            // Provider success ends feed coalescing; later Sync requests need another feed.
            unset($work['feed_claim']);
            $this->save($b, $job, ['offer_work' => OfferState::json($work), 'offer_feed_succeeded_at' => $this->at(), 'offer_feed_failure' => null]);
        } catch (ReadDeferred|EnomTestCallLimit) { $work['feed_due'] = $this->at() + 120; }
        catch (\Throwable $error) {
            $guard(); $work['tries'] = ($work['tries'] ?? 0) + 1;
            $work['feed_due'] = $this->at() + $this->backoff($work['tries']);
            $this->save($b, $job, ['offer_feed_failed_at' => $this->at(), 'offer_feed_failure' => ReadFailure::code($error)]);
        }
    }

    private function facts(Writer $writer, AccountBinding $b, string $tld, array &$work, \Closure $guard): void
    {
        $guard(); $state = $work['facts'][$tld];
        if (!empty($state['sold_only']) && !$this->sold($b, $tld)) { unset($work['facts'][$tld]); $work['facts_removed'][] = $tld; return; }
        try {
            $feed = ($this->feed)($b); $calls = $feed->factsReads();
            if ($calls === 0) {
                $work['facts'][$tld] = [...$state, 'unsupported' => true, 'due_at' => null];
                return;
            }
            $command = $this->read($b, RequestOrigin::Background, $calls, function ($context) use ($feed, $tld, &$state) {
                $state['tried_at'] = $this->at(); return $feed->facts($tld, $context);
            }, $guard);
            $guard(); $b->requireMatch($command->binding());
            if ($command->tld() !== $tld) { throw new \DomainException('Facts ending changed.'); }
            $writer->apply($command, $this->revision($b, $tld));
            $state['succeeded_at'] = $this->at(); $state['failure'] = null; $state['tries'] = 0;
            $due = max($this->at() + 120, $command->facts->freshUntil->getTimestamp());
        } catch (ReadDeferred|EnomTestCallLimit) { $due = $this->at() + 120; }
        catch (\Throwable $error) {
            $guard(); $state['tries'] = ($state['tries'] ?? 0) + 1; $state['failed_at'] = $this->at(); $state['failure'] = ReadFailure::code($error);
            $due = $this->at() + $this->backoff($state['tries']);
        }
        $work['facts'][$tld] = [...$state, 'due_at' => $due];
    }

    private function nextFacts(array $work): ?string
    {
        $ending = null; $due = PHP_INT_MAX;
        foreach ($work['facts'] ?? [] as $tld => $state) {
            if (!empty($state['unsupported'])) { continue; }
            if ($state['due_at'] <= $this->at() && ($state['due_at'] < $due || $state['due_at'] === $due && strcmp($tld, $ending ?? '') < 0)) {
                $ending = $tld; $due = $state['due_at'];
            }
        }
        return $ending;
    }

    private function read(AccountBinding $b, RequestOrigin $origin, int $calls, \Closure $read, ?\Closure $current = null): mixed
    {
        $this->outside();
        if ($calls < 1 || $calls > 10) { throw new \DomainException('Invalid feed read count.'); }
        if (($this->allowance)($b, $origin) < $calls) { throw new ReadDeferred('Our catalogue read allowance is unavailable.'); }
        $budget = new FeedReadBudget($this->minute, $b, $origin, $calls);
        try {
            return $read(new RequestContext($b, $origin, $this->clock->now()->modify('+'.($calls * 25 + 5).' seconds'),
                new RequestBudget($calls), $this->clock, $budget, stopOnReadFailure: true, current: $current));
        } finally { $budget->finish(); }
    }

    private function drain(Writer $writer, AccountBinding $b, array &$work): void
    {
        for ($i = 0; $i < self::BATCH && !empty($work['pending']); $i++) {
            $row = $work['pending'][0];
            $writer->apply(new FeedObserved($b, $row['tld'], array_map(OfferState::cost(...), $row['costs'])), $this->revision($b, $row['tld']));
            array_shift($work['pending']);
        }
    }

    private function republish(Writer $writer, AccountBinding $b, array &$work): void
    {
        $rows = Tld::where('server_id', $b->serverId)->where('id', '>', $work['local_cursor'] ?? 0)->orderBy('id')->limit(self::BATCH)->get();
        // Shared pricing failures still stop the account; only an unreadable own rule is skipped.
        $fee = CardFee::read(); $default = (new PricingRules)->defaultRule(false, $fee);
        foreach ($rows as $t) {
            $this->offerBinding($b, $t);
            if ($t->catalogue_published_revision === null && Pricing::hasOwn($t)) {
                try { Pricing::rule($t, $default, $fee); }
                catch (\DomainException|\InvalidArgumentException) { continue; }
            }
            $this->requestSoldFacts($b, $t->tld);
            $revision = $this->revision($b, $t->tld);
            if ($t->catalogue_published_revision === null || !OfferState::equivalent(OfferState::decode($t->catalogue_published_revision), get_object_vars($revision))) {
                $writer->apply(Edit::republish($b, $t->tld), $revision);
            }
        }
        $work['local_cursor'] = $rows->count() === self::BATCH ? $rows->last()->id : 0;
    }

    private function revision(AccountBinding $b, string $tld): Revision
    {
        $t = Tld::where('server_id', $b->serverId)->where('tld', $tld)->first();
        $a = $this->account($b); $p = DB::table('domains_sales_policies')->where('tld', $tld)->first();
        return new Revision((int) ($t?->catalogue_revision ?? 0), OfferState::decode($a->catalogue_defaults)['_revision'] ?? 0, (int) ($p?->revision ?? 0));
    }
    private function account(AccountBinding $b): Account
    {
        $a = Account::where('server_id', $b->serverId)->sole(); $b->requireMatch(AccountBindings::binding($a)); return $a;
    }
    private function offerBinding(AccountBinding $b, Tld $t): void
    {
        if ($t->account_fingerprint !== $b->fingerprint || $t->registrar_key !== $b->registrarKey || !$t->catalogue_converted) {
            throw new \DomainException('Offer binding or conversion changed.');
        }
    }
    private function editQueue(AccountBinding $b, \Closure $edit): void
    {
        $this->outside();
        DB::transaction(function () use ($b, $edit): void {
            Server::whereKey($b->serverId)->lockForUpdate()->firstOrFail();
            Account::where('server_id', $b->serverId)->lockForUpdate()->sole(); $this->fence($b); $this->account($b);
            DB::table('domains_observation_queues')->insertOrIgnore(['server_id' => $b->serverId, 'account_fingerprint' => $b->fingerprint,
                'offer_queue_created' => true]);
            $q = DB::table('domains_observation_queues')->where('server_id', $b->serverId);
            $row = (clone $q)->lockForUpdate()->firstOrFail();
            if ($row->account_fingerprint !== $b->fingerprint) { throw new \DomainException('Offer queue binding changed.'); }
            if ($row->offer_work === null) { $row->offer_work = '{}'; $q->update(['offer_work' => '{}']); }
            $edit($q, $row);
        });
    }
    private function current(AccountBinding $b, object $job): void
    {
        $this->fence($b); $this->account($b);
        if ($this->credentials && !hash_equals($job->credentialRevision, ($this->credentials)($b))) {
            throw new \DomainException('Offer work credentials changed.');
        }
        $row = DB::table('domains_observation_queues')->where('server_id', $b->serverId)->lockForUpdate()->firstOrFail();
        if ($row->account_fingerprint !== $b->fingerprint || $row->offer_claim !== $job->offer_claim
            || (int) $row->offer_feed_run !== (int) $job->offer_feed_run || (int) $row->offer_lease <= $this->at()) {
            throw new \DomainException('Offer work superseded or expired.');
        }
    }
    private function save(AccountBinding $b, object $job, array $values): void
    {
        $this->editQueue($b, function ($q) use ($b, $job, $values): void {
            $this->current($b, $job);
            if (isset($values['offer_work'])) {
                // Hints may arrive outside locks while the provider read is in flight.
                $stored = OfferState::decode((clone $q)->value('offer_work')); $next = OfferState::decode($values['offer_work']);
                $next['facts'] = [...array_diff_key($stored['facts'] ?? [], array_flip($next['facts_removed'] ?? [])), ...($next['facts'] ?? [])];
                unset($next['facts_removed']);
                if (!empty($stored['feed_hint'])) {
                    $next['feed_hint'] = true; $next['feed_due'] = $stored['feed_due']; $next['origin'] = $stored['origin'];
                }
                if (array_key_exists('offer_claim', $values) && $values['offer_claim'] === null) { unset($next['feed_claim']); }
                $values['offer_work'] = OfferState::json($next);
            }
            $q->update($values);
        });
    }
    private function fence(AccountBinding $b): void { if ($this->guard) { ($this->guard)($b->serverId); } }
    private function backoff(int $tries): int { return [120, 600, 3600, 21600][min(3, $tries - 1)]; }
    private function at(): int { return $this->clock->now()->getTimestamp(); }
    private function outside(): void
    { if (DB::transactionLevel() !== 0) { throw new \LogicException('Offer work requires an outside transaction.'); } }
}
