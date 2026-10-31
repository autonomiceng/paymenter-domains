<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Local 60-wire/minute ceiling, shared across origins. Vendor limits are additional, never inferred. */
final class AccountReadBudget implements ReadLimiter
{
    public function __construct(private readonly Clock $clock, private readonly int $backgroundLimit=60) {if($backgroundLimit<1||$backgroundLimit>60){throw new \InvalidArgumentException;}}
    /** A narrower background consumer shares usage and leases without double counting. */
    public function restricted(int $limit): self { return new self($this->clock,min($limit,$this->backgroundLimit)); }
    public function acquire(AccountBinding $binding, RequestOrigin $origin): string
    {
        OperationClaims::outside();
        return DB::transaction(function () use ($binding,$origin): string {
            $a=Account::where('server_id',$binding->serverId)->lockForUpdate()->firstOrFail();
            $binding->requireMatch(CatalogueAcceptances::binding($a));
            $now=$this->micros(); $q=DB::table('domains_read_budgets');
            $row=(clone $q)->where('server_id',$binding->serverId)->lockForUpdate()->first();
            $leases=$row?json_decode($row->leases,true,8,JSON_THROW_ON_ERROR):[];
            if ($row && $row->account_fingerprint!==$binding->fingerprint) { throw new \DomainException('Read budget binding changed.'); }
            // Lease outlives the adapter's fixed25s timeout. Crashed senders cannot free a live slot early.
            $leases=array_filter($leases,static fn($l)=>$l['until']>$now);
            $route=$binding->registrarKey==='namesilo'?$origin->value:'enom';
            $active=count(array_filter($leases,static fn($l)=>$l['route']===$route));
            $cap=$binding->registrarKey==='namesilo'&&$origin===RequestOrigin::Background?2:1;
            $window=$row?(int)$row->window_at:$now; $used=$row?(int)$row->used:0;
            if ($now-$window>=60000000) { $window=$now; $used=0; }
            if ($active>=$cap || $used>=($origin===RequestOrigin::Background?$this->backgroundLimit:60)) {
                throw new ReadDeferred('Account read budget unavailable.');
            }
            if ($binding->registrarKey==='namesilo' && $origin===RequestOrigin::Interactive && $row && $now<(int)$row->next_api_at) {
                throw new ReadDeferred('Account read budget unavailable.', (int)$row->next_api_at-$now);
            }
            $token=bin2hex(random_bytes(16)); $leases[$token]=['route'=>$route,'until'=>$now+30000000];
            $values=['account_fingerprint'=>$binding->fingerprint,'window_at'=>$window,'used'=>$used+1,'leases'=>json_encode($leases,JSON_THROW_ON_ERROR),
                'next_api_at'=>$binding->registrarKey==='namesilo'&&$origin===RequestOrigin::Interactive?$now+1000000:($row->next_api_at??0)];
            $q->updateOrInsert(['server_id'=>$binding->serverId],$values); return $token;
        });
    }
    public function release(AccountBinding $binding, RequestOrigin $origin, string $token): void
    {
        OperationClaims::outside();
        DB::transaction(function () use ($binding,$token): void {
            $a=Account::where('server_id',$binding->serverId)->lockForUpdate()->firstOrFail(); $binding->requireMatch(CatalogueAcceptances::binding($a));
            $q=DB::table('domains_read_budgets')->where('server_id',$binding->serverId); $row=(clone $q)->lockForUpdate()->firstOrFail();
            $leases=json_decode($row->leases,true,8,JSON_THROW_ON_ERROR); unset($leases[$token]);
            $q->update(['leases'=>json_encode($leases,JSON_THROW_ON_ERROR)]);
        });
    }
    private function micros(): int { $now=$this->clock->now(); return (int)$now->format('U')*1000000+(int)$now->format('u'); }
}
