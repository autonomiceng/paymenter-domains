<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, CatalogueAcceptances, ReadDeferred, ReadFailure};

/** Dormant account worker on the retained queue. No container binding, job or caller switch.
 * $feed supplies bound adapters using existing admitted transports (including daily wire caps).
 * $allowance supplies OUR daily headroom for this origin, preserving capacity for holding reads. */
final class OfferWork
{
    public const BATCH = 25;
    private const LEASE = 300;
    public function __construct(private readonly Writer $writer, private readonly Clock $clock,
        private readonly AccountReadBudget $minute, private readonly \Closure $feed, private readonly \Closure $allowance) {}

    /** Manual and automatic triggers enqueue the same account job. No provider I/O. */
    public function sync(AccountBinding $binding, RequestOrigin $origin = RequestOrigin::Interactive): void
    {
        $this->editQueue($binding, function ($q, $row) use ($origin): void {
            if ((int) $row->offer_lease > $this->at()) { return; }
            $work = OfferState::decode($row->offer_work);
            $work['feed_due'] = min($work['feed_due'] ?? $this->at(), $this->at());
            $work['origin'] = $origin->value;
            $q->update(['offer_work' => OfferState::json($work)]);
        });
    }

    /** Search/read hints only for a discovered offer. Repeated hints preserve failure backoff. */
    public function requestFacts(AccountBinding $binding, string $tld): void
    {
        new Query($binding, [$tld], 'register');
        $this->editQueue($binding, function ($q, $row) use ($binding, $tld): void {
            $t = Tld::where('server_id', $binding->serverId)->where('tld', $tld)->firstOrFail();
            $this->offerBinding($binding, $t);
            $facts = OfferState::facts($t->catalogue_facts);
            if ($t->offer_facts_due !== null) { return; }
            DB::table('domains_tlds')->where('id', $t->id)->update([
                'offer_facts_due' => $facts && $facts->fresh($this->clock->now()) ? $facts->freshUntil->getTimestamp() : $this->at()]);
        }, $tld);
    }

    /** Safe local status; no cost, credentials, raw error or acceptance-list copies. */
    public function status(AccountBinding $binding, ?string $tld = null): array
    {
        $this->account($binding);
        if ($tld !== null) {
            $t = Tld::where('server_id', $binding->serverId)->where('tld', $tld)->firstOrFail(); $this->offerBinding($binding, $t);
            $state = OfferState::decode($t->offer_facts_work); $facts = OfferState::facts($t->catalogue_facts);
            return [...$state, 'state' => $facts && $facts->fresh($this->clock->now()) ? 'ready' : ($t->offer_facts_due === null ? 'unknown' : 'queued'),
                'due_at' => $t->offer_facts_due === null ? null : (int) $t->offer_facts_due];
        }
        $row = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->where('account_fingerprint', $binding->fingerprint)->first();
        if (!$row || $row->offer_work === null) { return ['state' => 'idle']; }
        $work = OfferState::decode($row->offer_work);
        return ['state' => (int) $row->offer_lease > $this->at() ? 'running' : (!empty($work['pending']) || ($work['feed_due'] ?? 0) <= $this->at() ? 'queued' : 'ready'),
            'tried_at' => $row->offer_feed_tried_at, 'succeeded_at' => $row->offer_feed_succeeded_at,
            'failed_at' => $row->offer_feed_failed_at, 'failure' => $row->offer_feed_failure,
            'due_at' => $work['feed_due'] ?? null, 'remaining' => count($work['pending'] ?? [])];
    }

    /** Fair bounded rotation, including accounts with only lazy facts/local republication. */
    public function tick(int $accounts = 8): array
    {
        $this->outside();
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

    private function run(int $server): string
    {
        $account = Account::where('server_id', $server)->sole(); $b = CatalogueAcceptances::binding($account);
        $job = null;
        $this->editQueue($b, function ($q, $row) use (&$job): void {
            if ((int) $row->offer_lease > $this->at()) { return; }
            $job = $row; $job->offer_claim = bin2hex(random_bytes(16)); $job->offer_feed_run++;
            $q->update(['offer_claim' => $job->offer_claim, 'offer_lease' => $this->at() + self::LEASE, 'offer_feed_run' => $job->offer_feed_run]);
        });
        if (!$job) { return 'busy'; }
        $guard = function () use ($b, $job): void { $this->current($b, $job); };
        $writer = $this->writer->withGuard($guard); $work = OfferState::decode($job->offer_work);
        try {
            $this->republish($writer, $b, $work);
            if (!empty($work['pending'])) { $this->drain($writer, $b, $work); }
            else {
                $t = Tld::where('server_id', $server)->where('account_fingerprint', $b->fingerprint)
                    ->whereNotNull('offer_facts_due')->where('offer_facts_due', '<=', $this->at())->orderBy('offer_facts_due')->orderBy('id')->first();
                $due = ($work['feed_due'] ?? PHP_INT_MAX) <= $this->at();
                if ($t && (!$due || ($work['last'] ?? '') === 'feed')) {
                    $work['last'] = 'facts'; $this->facts($writer, $b, $job, $t);
                } elseif ($due) {
                    $work['last'] = 'feed'; $this->prices($b, $job, $work, $guard);
                    // Durable buffer precedes per-offer writes; a crash can resume without rereading.
                    $this->save($b, $job, ['offer_work' => OfferState::json($work)]);
                    $this->drain($writer, $b, $work);
                }
            }
            $this->save($b, $job, ['offer_work' => OfferState::json($work), 'offer_claim' => null, 'offer_lease' => 0]);
            return 'worked';
        } catch (\Throwable $error) {
            // Keep a durable pending buffer on local writer failure; never repeat its provider feed.
            $this->save($b, $job, ['offer_claim' => null, 'offer_lease' => 0]);
            throw $error;
        }
    }

    private function prices(AccountBinding $b, object $job, array &$work, \Closure $guard): void
    {
        $origin = RequestOrigin::from($work['origin'] ?? RequestOrigin::Background->value);
        try {
            $feed = ($this->feed)($b); $calls = $feed->priceReads();
            $rows = $this->read($b, $origin, $calls, function ($context) use ($feed, $guard, $b, $job): array {
                $this->save($b, $job, ['offer_feed_tried_at' => $this->at()]);
                return FeedRun::prices($feed, $context, $guard);
            });
            if (count($rows) > 5000) { throw new \DomainException('Oversized offer feed.'); }
            $pending = [];
            foreach ($rows as $r) { $pending[] = ['tld' => $r->tld(), 'costs' => array_values(array_map(OfferState::encodeCost(...), $r->costs))]; }
            if (strlen(OfferState::json($pending)) > 8 * 1024 * 1024) { throw new \DomainException('Oversized offer feed.'); }
            $work['pending'] = $pending; $work['feed_due'] = $this->at() + 86400; $work['tries'] = 0;
            $work['origin'] = RequestOrigin::Background->value;
            $this->save($b, $job, ['offer_feed_succeeded_at' => $this->at(), 'offer_feed_failure' => null]);
        } catch (ReadDeferred) { $work['feed_due'] = $this->at() + 120; }
        catch (\Throwable $error) {
            $guard(); $work['tries'] = ($work['tries'] ?? 0) + 1;
            $work['feed_due'] = $this->at() + $this->backoff($work['tries']);
            $this->save($b, $job, ['offer_feed_failed_at' => $this->at(), 'offer_feed_failure' => ReadFailure::code($error)]);
        }
    }

    private function facts(Writer $writer, AccountBinding $b, object $job, Tld $t): void
    {
        $state = OfferState::decode($t->offer_facts_work);
        try {
            $feed = ($this->feed)($b); $calls = $feed->factsReads();
            if ($calls === 0) { throw new \DomainException('Offer facts are unverified.'); }
            $command = $this->read($b, RequestOrigin::Background, $calls, function ($context) use ($feed, $t, &$state) {
                $state['tried_at'] = $this->at(); return $feed->facts($t->tld, $context);
            });
            $b->requireMatch($command->binding());
            if ($command->tld() !== $t->tld) { throw new \DomainException('Facts ending changed.'); }
            $writer->apply($command, $this->revision($b, $t->tld));
            $state['succeeded_at'] = $this->at(); $state['failure'] = null; $state['tries'] = 0;
            $due = max($this->at() + 120, $command->facts->freshUntil->getTimestamp());
        } catch (ReadDeferred) { $due = $this->at() + 120; }
        catch (\Throwable $error) {
            $state['tries'] = ($state['tries'] ?? 0) + 1; $state['failed_at'] = $this->at(); $state['failure'] = ReadFailure::code($error);
            $due = $this->at() + $this->backoff($state['tries']);
        }
        $this->editQueue($b, function () use ($b, $job, $t, $state, $due): void {
            $this->current($b, $job);
            DB::table('domains_tlds')->where('id', $t->id)->where('account_fingerprint', $b->fingerprint)
                ->update(['offer_facts_work' => OfferState::json($state), 'offer_facts_due' => $due]);
        }, $t->tld);
    }

    private function read(AccountBinding $b, RequestOrigin $origin, int $calls, \Closure $read): mixed
    {
        $this->outside();
        if ($calls < 1 || $calls > 10) { throw new \DomainException('Invalid feed read count.'); }
        if (($this->allowance)($b, $origin) < $calls) { throw new ReadDeferred('Our catalogue read allowance is unavailable.'); }
        $budget = new FeedReadBudget($this->minute, $b, $origin, $calls);
        try {
            return $read(new RequestContext($b, $origin, $this->clock->now()->modify('+'.($calls * 25 + 5).' seconds'),
                new RequestBudget($calls), $this->clock, $budget, stopOnReadFailure: true));
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
        foreach ($rows as $t) {
            $this->offerBinding($b, $t); $revision = $this->revision($b, $t->tld);
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
        $a = Account::where('server_id', $b->serverId)->sole(); $b->requireMatch(CatalogueAcceptances::binding($a)); return $a;
    }
    private function offerBinding(AccountBinding $b, Tld $t): void
    {
        if ($t->account_fingerprint !== $b->fingerprint || $t->registrar_key !== $b->registrarKey || !$t->catalogue_converted) {
            throw new \DomainException('Offer binding or conversion changed.');
        }
    }
    private function editQueue(AccountBinding $b, \Closure $edit, ?string $tld = null): void
    {
        $this->outside();
        DB::transaction(function () use ($b, $edit, $tld): void {
            Server::whereKey($b->serverId)->lockForUpdate()->firstOrFail();
            Account::where('server_id', $b->serverId)->lockForUpdate()->sole(); $this->account($b);
            if ($tld !== null) { Tld::where('server_id', $b->serverId)->where('tld', $tld)->lockForUpdate()->firstOrFail(); }
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
        $this->account($b);
        $row = DB::table('domains_observation_queues')->where('server_id', $b->serverId)->lockForUpdate()->firstOrFail();
        if ($row->account_fingerprint !== $b->fingerprint || $row->offer_claim !== $job->offer_claim
            || (int) $row->offer_feed_run !== (int) $job->offer_feed_run || (int) $row->offer_lease <= $this->at()) {
            throw new \DomainException('Offer work superseded or expired.');
        }
    }
    private function save(AccountBinding $b, object $job, array $values): void
    {
        $this->editQueue($b, function ($q) use ($b, $job, $values): void { $this->current($b, $job); $q->update($values); });
    }
    private function backoff(int $tries): int { return [120, 600, 3600, 21600][min(3, $tries - 1)]; }
    private function at(): int { return $this->clock->now()->getTimestamp(); }
    private function outside(): void
    { if (DB::transactionLevel() !== 0) { throw new \LogicException('Offer work requires an outside transaction.'); } }
}
