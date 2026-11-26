<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Feed;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting, Edit, FeedObserved, OfferState, Pricing, Revision, Writer};
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, CardFee, EnomTestCallLimit, PricingRules, ReadDeferred, ReadFailure, RuntimeDiagnostics};

/** One claimed account turn: local republication, then lazy facts or the price feed, then a bounded drain. */
final class FeedTurn
{
    private const LEASE = 300;
    public function __construct(private readonly Writer $writer, private readonly Clock $clock,
        private readonly AccountReadBudget $minute, private readonly \Closure $feed, private readonly \Closure $allowance,
        private readonly FeedQueue $queue, private readonly \Closure $soldFacts, private readonly ?\Closure $credentials = null,
        private readonly ?int $generation = null, private readonly ?\Closure $factsEvidence = null) {}

    public function run(int $server): string
    {
        $account = Account::where('server_id', $server)->sole(); $b = AccountBindings::binding($account);
        $credential = $this->credentials ? ($this->credentials)($b) : null;
        $job = $this->claim($b);
        if (!$job) { return 'busy'; }
        $job->credentialRevision = $credential;
        $guard = function () use ($b, $job): void { $this->queue->current($b, $job); };
        $writer = $this->writer->withGuard($guard); $work = OfferState::decode($job->offer_work); $touched = [];
        try {
            $this->advance($writer, $b, $job, $credential, $work, $guard, $touched);
            unset($work['local_failure'], $work['local_failed_at']);
            $this->queue->save($b, $job, ['offer_work' => OfferState::json($work), 'offer_claim' => null, 'offer_lease' => 0]);
            return 'worked';
        } catch (\Throwable $error) {
            $this->keepFailure($b, $job, $work, $error);
            throw $error;
        } finally {
            if ($touched) {
                try {
                    CallerRouting::resolve(\Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers::class)
                        ?->warnPriceMismatches(array_keys($touched), $b->serverId);
                } catch (\Throwable) { /* Diagnostics cannot replace the saved result or its original failure. */ }
            }
        }
    }

    private function claim(AccountBinding $b): ?object
    {
        $job = null;
        $this->queue->edit($b, function ($q, $row) use (&$job): void {
            if ((int) $row->offer_lease > $this->at()) { return; }
            $job = $row; $job->offer_claim = bin2hex(random_bytes(16)); $job->offer_feed_run++;
            $job->factsRead = OfferState::decode($row->offer_work)['facts'] ?? [];
            $q->update(['offer_claim' => $job->offer_claim, 'offer_lease' => $this->at() + self::LEASE, 'offer_feed_run' => $job->offer_feed_run]);
        });
        return $job;
    }

    private function advance(Writer $writer, AccountBinding $b, object $job, ?string $credential, array &$work, \Closure $guard, array &$touched): void
    {
        if ($this->credentials && ($work['credential_revision'] ?? null) !== $credential
            || $this->generation !== null && ($work['catalogue_generation'] ?? null) !== $this->generation) {
            // A durable buffer belongs to the credentials that fetched it, even after restart.
            $work['pending'] = []; $work['feed_due'] = $this->at(); $work['tries'] = 0;
            $work['credential_revision'] = $credential; $work['catalogue_generation'] = $this->generation;
        }
        $this->republish($writer, $b, $work, $guard, $touched);
        // Republication can wake retained facts when reviewed authority changes.
        $work['facts'] = $job->factsRead = OfferState::decode(DB::table('domains_observation_queues')->where('server_id', $b->serverId)->value('offer_work'))['facts'] ?? [];
        $this->checkFactsEvidence($b, $credential, $work);
        $t = $this->nextFacts($work);
        if (!empty($work['pending'])) {
            if ($t) { $this->facts($writer, $b, $t, $work, $guard, $touched); }
            $this->drain($writer, $b, $work, $touched);
            return;
        }
        $due = ($work['feed_due'] ?? PHP_INT_MAX) <= $this->at();
        if ($t && (!$due || ($work['last'] ?? '') === 'feed')) {
            $work['last'] = 'facts'; $this->facts($writer, $b, $t, $work, $guard, $touched);
        } elseif ($due) {
            $this->beginFeed($b, $job, $work);
            $work['last'] = 'feed';
            // Durable buffer precedes per-offer writes; a crash can resume without rereading.
            if (!$this->prices($b, $job, $work, $guard)) { $this->queue->save($b, $job, ['offer_work' => OfferState::json($work)]); }
            $this->drain($writer, $b, $work, $touched);
        }
    }

    /** Keep a durable pending buffer on local writer failure; never repeat its provider feed. */
    private function keepFailure(AccountBinding $b, object $job, array $work, \Throwable $error): void
    {
        $work['local_failure'] = ReadFailure::code($error); $work['local_failed_at'] = $this->at();
        try {
            $this->queue->save($b, $job, ['offer_work' => OfferState::json($work), 'offer_claim' => null, 'offer_lease' => 0]);
        } catch (\Throwable) {
            // Superseded work releases only the claim/run it owns; no buffer or success changes.
            DB::table('domains_observation_queues')->where('server_id', $b->serverId)->where('account_fingerprint', $b->fingerprint)
                ->where('offer_claim', $job->offer_claim)->where('offer_feed_run', $job->offer_feed_run)
                ->update(['offer_claim' => null, 'offer_lease' => 0]);
        }
    }
    private function beginFeed(AccountBinding $b, object $job, array &$work): void
    {
        $this->queue->edit($b, function ($q, $row) use ($b, $job, &$work): void {
            $this->queue->current($b, $job); $stored = OfferState::decode($row->offer_work);
            // This feed satisfies hints received during earlier local work in the same turn.
            if (!empty($stored['feed_hint'])) { $work['origin'] = $stored['origin']; }
            $stored['feed_claim'] = $work['feed_claim'] = $job->offer_claim;
            unset($stored['feed_hint'], $work['feed_hint']);
            $q->update(['offer_work' => OfferState::json($stored)]);
        });
    }

    /** Returns whether it saved the buffer with the success checkpoint. */
    private function prices(AccountBinding $b, object $job, array &$work, \Closure $guard): bool
    {
        $origin = RequestOrigin::from($work['origin'] ?? RequestOrigin::Background->value);
        try {
            $feed = ($this->feed)($b); $calls = $feed->priceReads();
            $rows = $this->read($b, $origin, $calls, function ($context) use ($feed, $guard, $b, $job): array {
                $this->queue->save($b, $job, ['offer_feed_tried_at' => $this->at()]);
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
            $this->queue->save($b, $job, ['offer_work' => OfferState::json($work), 'offer_feed_succeeded_at' => $this->at(), 'offer_feed_failure' => null]);
            return true;
        } catch (ReadDeferred|EnomTestCallLimit) { $work['feed_due'] = $this->at() + 120; }
        catch (\Throwable $error) {
            $guard(); $work['tries'] = ($work['tries'] ?? 0) + 1;
            $work['feed_due'] = $this->at() + $this->backoff($work['tries']);
            $this->queue->save($b, $job, ['offer_feed_failed_at' => $this->at(), 'offer_feed_failure' => ReadFailure::code($error)]);
        }
        return false;
    }

    private function facts(Writer $writer, AccountBinding $b, string $tld, array &$work, \Closure $guard, array &$touched): void
    {
        $guard(); $state = $work['facts'][$tld];
        if (!empty($state['sold_only']) && !$this->queue->sold($b, $tld)) { unset($work['facts'][$tld]); $work['facts_removed'][] = $tld; return; }
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
            $writer->apply($command, $this->revision($b, $tld)); $touched[$tld] = true;
            $state['succeeded_at'] = $this->at(); $state['failure'] = null; $state['tries'] = 0;
            $due = max($this->at() + 120, $command->facts->freshUntil->getTimestamp());
        } catch (ReadDeferred|EnomTestCallLimit) { $due = $this->at() + 120; }
        catch (\Throwable $error) {
            $guard(); $state['tries'] = ($state['tries'] ?? 0) + 1; $state['failed_at'] = $this->at(); $state['failure'] = ReadFailure::code($error);
            $due = $this->at() + $this->backoff($state['tries']);
        }
        $work['facts'][$tld] = [...$state, 'due_at' => $due];
    }

    /** Some terms require independent local evidence before a provider lookup can confirm the ending. */
    private function checkFactsEvidence(AccountBinding $binding, ?string $revision, array &$work): void
    {
        if (!$this->factsEvidence || $revision === null || empty($work['facts'])) { return; }
        // A failed terms lookup must still let already-fetched prices drain under the normal writer fence.
        try { $check = ($this->factsEvidence)($binding, $revision); }
        catch (\Throwable) { $check = static fn (): bool => false; }
        if ($check === null) { return; }

        foreach ($work['facts'] as $tld => $state) {
            try { $ready = $check((string) $tld); }
            catch (\Throwable) { $ready = false; }
            if (!$ready) {
                $work['facts'][$tld] = [...$state, 'waiting_for_evidence' => true,
                    'failure' => 'catalogue_evidence_missing', 'due_at' => null];
            } elseif (!empty($state['waiting_for_evidence'])) {
                unset($state['waiting_for_evidence'], $state['failure'], $state['tries']);
                $work['facts'][$tld] = [...$state, 'due_at' => $this->at()];
            }
        }
    }

    private function nextFacts(array $work): ?string
    {
        $ending = null; $due = PHP_INT_MAX;
        foreach ($work['facts'] ?? [] as $tld => $state) {
            if (!empty($state['unsupported']) || !empty($state['waiting_for_evidence'])) { continue; }
            if ($state['due_at'] <= $this->at() && ($state['due_at'] < $due || $state['due_at'] === $due && strcmp($tld, $ending ?? '') < 0)) {
                $ending = $tld; $due = $state['due_at'];
            }
        }
        return $ending;
    }

    private function read(AccountBinding $b, RequestOrigin $origin, int $calls, \Closure $read, ?\Closure $current = null): mixed
    {
        FeedQueue::outside();
        if ($calls < 1 || $calls > 10) { throw new \DomainException('Invalid feed read count.'); }
        if (($this->allowance)($b, $origin) < $calls) { throw new ReadDeferred('Our catalogue read allowance is unavailable.'); }
        $budget = new FeedReadBudget($this->minute, $b, $origin, $calls);
        try {
            return $read(new RequestContext($b, $origin, $this->clock->now()->modify('+'.($calls * 25 + 5).' seconds'),
                new RequestBudget($calls), $this->clock, $budget, stopOnReadFailure: true, current: $current));
        } finally { $budget->finish(); }
    }

    private function drain(Writer $writer, AccountBinding $b, array &$work, array &$touched): void
    {
        for ($i = 0; $i < OfferWork::BATCH && !empty($work['pending']); $i++) {
            $row = $work['pending'][0];
            $writer->apply(new FeedObserved($b, $row['tld'], array_map(OfferState::cost(...), $row['costs'])), $this->revision($b, $row['tld']));
            $touched[$row['tld']] = true;
            array_shift($work['pending']);
        }
    }

    private function republish(Writer $writer, AccountBinding $b, array &$work, \Closure $guard, array &$touched): void
    {
        $rows = Tld::where('server_id', $b->serverId)->where('id', '>', $work['local_cursor'] ?? 0)->orderBy('id')->limit(OfferWork::BATCH)->get();
        // Shared pricing failures still stop the account; only an unreadable own rule is skipped.
        $fee = CardFee::read(); $default = (new PricingRules)->defaultRule(false, $fee);
        foreach ($rows as $t) {
            $this->queue->offerBinding($b, $t);
            if ($t->catalogue_published_revision === null && Pricing::hasOwn($t)) {
                try { Pricing::rule($t, $default, $fee); }
                catch (\DomainException|\InvalidArgumentException) { continue; }
            }
            ($this->soldFacts)($b, $t->tld);
            $revision = $this->revision($b, $t->tld);
            // A marker that can't be read is republished like a stale one.
            try { $published = OfferState::equivalent(OfferState::decode($t->catalogue_published_revision), get_object_vars($revision)); }
            catch (\JsonException|\TypeError) { $published = false; }
            if ($t->catalogue_published_revision === null || !$published) {
                try { $writer->apply(Edit::republish($b, $t->tld), $revision); $touched[$t->tld] = true; }
                catch (\Throwable $error) {
                    // An obsolete account claim still stops the turn; one failed projection cannot pin the scan.
                    $guard();
                    (new RuntimeDiagnostics($this->clock))->attempt('publication', static fn () => throw $error, (int) $t->id);
                }
            }
        }
        $work['local_cursor'] = $rows->count() === OfferWork::BATCH ? $rows->last()->id : 0;
    }

    private function revision(AccountBinding $b, string $tld): Revision
    {
        $t = Tld::where('server_id', $b->serverId)->where('tld', $tld)->first();
        $a = $this->queue->account($b); $p = DB::table('domains_sales_policies')->where('tld', $tld)->first();
        return new Revision((int) ($t?->catalogue_revision ?? 0), OfferState::decode($a->catalogue_defaults)['_revision'] ?? 0, (int) ($p?->revision ?? 0));
    }
    private function backoff(int $tries): int { return [120, 600, 3600, 21600][min(3, $tries - 1)]; }
    private function at(): int { return $this->clock->now()->getTimestamp(); }
}
