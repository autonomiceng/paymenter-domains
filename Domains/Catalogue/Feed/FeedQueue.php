<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Feed;
use Paymenter\Extensions\Servers\Domains\Catalogue\OfferState;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};

/** The account's row in the retained queue: locked edits, the claimed turn's fence and its saves. */
final class FeedQueue
{
    public function __construct(private readonly Clock $clock, private readonly ?\Closure $credentials = null,
        private readonly ?\Closure $guard = null) {}

    public function sold(AccountBinding $binding, string $tld): bool
    {
        $t = Tld::where('server_id', $binding->serverId)->where('tld', $tld)->sole();
        $this->offerBinding($binding, $t);
        return OfferState::sold($binding, $t, OfferState::decode($this->account($binding)->catalogue_defaults),
            DB::table('domains_sales_policies')->where('tld', $tld)->first());
    }
    public function edit(AccountBinding $b, \Closure $edit): void
    {
        self::outside();
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
    public function current(AccountBinding $b, object $job): void
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
    public function save(AccountBinding $b, object $job, array $values): void
    {
        $this->edit($b, function ($q) use ($b, $job, $values): void {
            $this->current($b, $job);
            if (isset($values['offer_work'])) {
                // Hints may arrive outside locks while the provider read is in flight.
                $stored = OfferState::decode((clone $q)->value('offer_work')); $next = OfferState::decode($values['offer_work']);
                $copied = $job->factsRead;
                foreach ($next['facts'] ?? [] as $tld => $state) {
                    // Keep hints promoted since the turn last read the facts unless it processed the entry.
                    if (isset($stored['facts'][$tld], $copied[$tld]) && $state === $copied[$tld]) {
                        $next['facts'][$tld] = $stored['facts'][$tld];
                    }
                }
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
    public function account(AccountBinding $b): Account
    {
        $a = Account::where('server_id', $b->serverId)->sole(); $b->requireMatch(AccountBindings::binding($a)); return $a;
    }
    public function offerBinding(AccountBinding $b, Tld $t): void
    {
        if ($t->account_fingerprint !== $b->fingerprint || $t->registrar_key !== $b->registrarKey) {
            throw new \DomainException('Offer binding changed.');
        }
    }
    public function fence(AccountBinding $b): void { if ($this->guard) { ($this->guard)($b->serverId); } }
    public function at(): int { return $this->clock->now()->getTimestamp(); }
    public static function outside(): void
    { if (DB::transactionLevel() !== 0) { throw new \LogicException('Offer work requires an outside transaction.'); } }
}
