<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Feed;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CatalogueActivation, OfferState, Query, TermSupport, Writer};

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, ReadFailure};

/** Dormant account worker on the retained queue. No container binding, job or caller switch.
 * $feed supplies bound adapters using existing admitted transports (including daily wire caps).
 * $allowance supplies OUR daily headroom for this origin, preserving capacity for holding reads. */
final class OfferWork
{
    public const BATCH = 25;
    private readonly FeedQueue $queue;
    private readonly FeedTurn $turn;
    public function __construct(private readonly Writer $writer, private readonly Clock $clock,
        private readonly AccountReadBudget $minute, private readonly \Closure $feed, private readonly \Closure $allowance,
        private readonly ?\Closure $credentials = null, private readonly ?int $generation = null,
        private readonly ?\Closure $guard = null, private readonly ?\Closure $factsEvidence = null) {
        if (($generation === null) !== ($guard === null)) { throw new \LogicException('Generation and guard must be composed together.'); }
        $this->queue = new FeedQueue($clock, $credentials, $guard);
        $this->turn = new FeedTurn($writer, $clock, $minute, $feed, $allowance, $this->queue, $this->requestSoldFacts(...),
            $credentials, $generation, $factsEvidence);
    }

    /** Manual and automatic triggers enqueue the same account job. No provider I/O. */
    public function sync(AccountBinding $binding, RequestOrigin $origin = RequestOrigin::Interactive, ?int $expectedGeneration = null): void
    {
        $this->queue->edit($binding, function ($q, $row) use ($origin, $expectedGeneration): void {
            if ($expectedGeneration !== null && $expectedGeneration !== CatalogueActivation::generation()) { throw new \DomainException('Queued offer generation changed.'); }
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
    { $this->queue->edit($binding, static function (): void {}); }

    /** Search/read hints only for a discovered offer. Repeated hints preserve failure backoff. */
    public function requestFacts(AccountBinding $binding, string $tld, bool $soldOnly = false): void
    {
        new Query($binding, [$tld], 'register');
        $this->queue->edit($binding, function ($q, $row) use ($binding, $tld, $soldOnly): void {
            $t = Tld::where('server_id', $binding->serverId)->where('tld', $tld)->firstOrFail();
            $this->queue->offerBinding($binding, $t); $work = OfferState::decode($row->offer_work);
            $facts = OfferState::facts($t->catalogue_facts);
            $rejected = !OfferState::testFactsAccepted($binding, $tld, $facts, $this->clock);
            $state = $work['facts'][$tld] ?? null;
            $authority = hash('sha256', OfferState::json(TermSupport::evidence($binding, $tld)));
            $current = $facts && TermSupport::currentFacts($binding, $tld, $facts);
            if ($state !== null && !$current && $binding->registrarKey === 'enom'
                && ($state['authority'] ?? null) !== $authority) {
                // Wake once for a changed approval; repeated hints preserve the new failure backoff.
                $work['facts'][$tld] = ['sold_only' => $state['sold_only'] ?? $soldOnly,
                    'authority' => $authority, 'due_at' => $this->at()];
            } elseif ($state !== null) {
                // Promote an old freshness deadline, while preserving failures and unsupported readers.
                if (!$rejected || !empty($state['tries']) || !empty($state['unsupported'])
                    || ($state['due_at'] ?? 0) <= $this->at()) { return; }
                $work['facts'][$tld]['due_at'] = $this->at();
            } else {
                if (count($work['facts'] ?? []) >= 5000) { throw new \DomainException('Offer facts queue is full.'); }
                $work['facts'][$tld] = ['sold_only' => $soldOnly, 'authority' => $authority, 'due_at' => OfferState::readyFacts($binding, $tld, $facts, $this->clock) ? $facts->freshUntil->getTimestamp() : $this->at()];
            }
            $q->update(['offer_work' => OfferState::json($work)]);
        });
    }

    /** Production hints are restricted to the selected sold set, never every discovered ending. */
    public function requestSoldFacts(AccountBinding $binding, string $tld): void
    {
        if (!$this->queue->sold($binding, $tld)) { return; }
        $this->requestFacts($binding, $tld, true);
    }

    /** Safe local status; no cost, credentials, raw error or acceptance-list copies. */
    public function status(AccountBinding $binding, ?string $tld = null): array
    {
        $this->queue->fence($binding); $this->queue->account($binding);
        $row = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->where('account_fingerprint', $binding->fingerprint)->first();
        $work = OfferState::decode($row?->offer_work);
        if ($this->generation !== null && ($work['catalogue_generation'] ?? null) !== $this->generation) { return ['state' => 'queued']; }
        $failure = empty($work['local_failure']) ? [] : ['state' => 'retrying', 'failure' => $work['local_failure'],
            'local_failure' => true, 'failed_at' => $work['local_failed_at'], 'due_at' => null];
        if ($tld !== null) {
            $t = Tld::where('server_id', $binding->serverId)->where('tld', $tld)->firstOrFail(); $this->queue->offerBinding($binding, $t);
            if ($failure) { return $failure; }
            $state = $work['facts'][$tld] ?? []; $facts = OfferState::facts($t->catalogue_facts);
            $status = match (true) {
                OfferState::readyFacts($binding, $tld, $facts, $this->clock) => 'ready',
                !$state => 'unknown',
                !empty($state['waiting_for_evidence']) => 'waiting_for_evidence',
                !empty($state['unsupported']) => 'unsupported',
                !empty($state['failure']) => 'retrying',
                default => 'queued',
            };
            return [...$state, 'state' => $status,
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
        $this->queue->fence($binding); $this->queue->account($binding);
        $row = DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->first();
        if (!$row || $row->account_fingerprint !== $binding->fingerprint) { return false; }
        $work = OfferState::decode($row->offer_work); $now = $this->clock->now();
        if ($row->offer_claim !== null || (int) $row->offer_lease > $this->at()
            || (int) $row->offer_feed_succeeded_at <= $after->getTimestamp()
            || (int) $row->offer_feed_succeeded_at > $this->at()
            || (int) $row->offer_feed_tried_at > (int) $row->offer_feed_succeeded_at
            || $row->offer_feed_failure !== null || !empty($work['pending']) || !empty($work['local_failure'])
            || !empty($work['tries']) || !empty($work['feed_hint']) || ($work['feed_due'] ?? 0) <= $this->at()
            || $this->generation !== null && ($work['catalogue_generation'] ?? null) !== $this->generation
            || $this->credentials && ($work['credential_revision'] ?? null) !== ($this->credentials)($binding)) { return false; }
        if (!$endings) { return false; }
        foreach ($endings as $ending) {
            $t = Tld::where('server_id', $binding->serverId)->where('tld', $ending)->first();
            if (!$t) { return false; }
            $this->queue->offerBinding($binding, $t);
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
        FeedQueue::outside();
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
            try { $out[$id] = $this->turn->run((int) $id); }
            catch (\Throwable $error) { $out[$id] = ReadFailure::code($error); }
        }
        return $out;
    }

    public function accountTurn(int $server): string { FeedQueue::outside(); return $this->turn->run($server); }

    private function at(): int { return $this->clock->now()->getTimestamp(); }
}
