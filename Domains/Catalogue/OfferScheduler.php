<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, HoldingObservationWork, OperationClaims, ReadFailure};

/** One shared account rotation. No job or timer registration; composition owns its generation. */
final class OfferScheduler
{
    public function __construct(private readonly OfferWork $offers, private readonly HoldingObservationWork $holdings,
        private readonly Clock $clock, private readonly \Closure $current) {}
    public static function routed(): ?self { return CallerRouting::legacy(static fn () => null); }
    public function enroll(int $server): void
    { $this->offers->wake(CatalogueAcceptances::binding(Account::where('server_id', $server)->sole())); }
    public function sync(AccountBinding $b, RequestOrigin $origin = RequestOrigin::Interactive): void { $this->offers->sync($b, $origin); }
    public function wake(AccountBinding $b): void { $this->offers->wake($b); }
    public function requestFacts(AccountBinding $b, string $tld): void { $this->offers->requestFacts($b, $tld); }
    public function status(AccountBinding $b, ?string $tld = null): array { return $this->offers->status($b, $tld); }
    public function tick(int $accounts = 8): array
    {
        OperationClaims::outside(); ($this->current)();
        if ($accounts < 1 || $accounts > 32) { throw new \InvalidArgumentException('Invalid account batch.'); }
        // Discover missing jobs and incomplete old enrollments without old price-fill/publication producers.
        foreach (Account::whereNotIn('server_id', DB::table('domains_observation_queues')->whereNotNull('offer_work')->select('server_id'))
            ->orderBy('server_id')->limit(8)->get() as $account) {
            try { $this->enroll((int)$account->server_id); } catch (\Throwable) { /* Independent account. */ }
        }
        $rows = DB::transaction(function () use ($accounts): array {
            ($this->current)(); $q = DB::table('domains_observation_queues');
            $rows = (clone $q)->orderBy('turn')->orderBy('server_id')->limit($accounts)->lockForUpdate()->get();
            $turn = (int)$q->max('turn');
            foreach ($rows as $r) { (clone $q)->where('id', $r->id)->update(['turn' => ++$turn]); }
            return $rows->all();
        });
        $out = [];
        foreach ($rows as $row) {
            try { $out[$row->server_id]['holding'] = $this->holdings->turn($row, $this->current); }
            catch (\Throwable $e) { $out[$row->server_id]['holding'] = ReadFailure::code($e); }
            try { $out[$row->server_id]['offer'] = $this->offers->accountTurn((int)$row->server_id); }
            catch (\Throwable $e) { $out[$row->server_id]['offer'] = ReadFailure::code($e); }
        }
        return $out;
    }
}
