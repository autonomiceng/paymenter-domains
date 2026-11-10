<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\{Invoice, Service, ServiceCancellation};
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Adoption, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{NativeCancellation, ObligationAlerts, OwnedDomains, RenewalCycles, RenewalStop};
/** What the staff status and the Registration card read beyond the domain row, in one batch per page.
 * Hides nothing from history: the alert rows stay until an inspection closes them. */
final class StaffDomainInputs
{
    /** Renewal request states that still have a next step. */
    private const OPEN_RENEWAL=['awaiting_payment','queued','running','pending','held','uncertain'];
    /** @param iterable<Domain> $domains
     * @param ?array $ordersOff an OperationQueries::ordersOffAccounts() snapshot, shared with the list's Status filter. Null reads it when a row needs it.
     * @return array<int,array{alerts:list<array{code:string,request:?int}>,late:bool,renewal:?Operation,off:?bool,invoice:?Invoice,closed:bool,waiting:?Operation,orders_off:bool,accounts:?array}> by domain id.
     * off: automatic renewal is off, null when its saved data can't be read. invoice and closed: what OwnedDomains::order reads. waiting: the newest request not finished.
     * orders_off: Orders are off for the account of a queued registration or transfer request. accounts: the snapshot the row's note and step read.
     * $release: for the alert count, which needs only whether staff must act. Each row then carries release, the page's registrar accounts read
     * once, and RequestFacts::readyMessage skips its wording-only release check, so the count reads a fixed number of queries per page. */
    public static function for(iterable $domains, ?array $ordersOff=null, bool $release=false): array
    {
        $domains=collect($domains); $ids=$domains->pluck('id')->all();
        $queued=$domains->filter(fn(Domain $d)=>$d->latestAcquisition?->state==='queued')->keyBy('id');
        // Without a queued registration or transfer no row reads the snapshot for its word, so its notes read the accounts now.
        $ordersOff??=$queued->isEmpty()?null:OperationQueries::ordersOffAccounts();
        $open=Schema::hasTable('domains_obligation_alerts') ? ObligationAlerts::open($ids)
            ->orderBy('id')->get(['domain_id','code','operation_id','last_seen'])->groupBy('domain_id') : collect();
        // Every renewal, because only the newest one counts. All requests of a domain with a linked alert, to check its fixes.
        $linked=$open->flatten(1)->whereNotNull('operation_id')->pluck('domain_id')->unique()->values()->all();
        $operations=Operation::query()->whereIn('domain_id',$ids)->where(fn($q)=>$q->where('action','renew')->orWhereIn('domain_id',$linked))
            ->orderBy('id')->get()->groupBy('domain_id');
        $services=$domains->pluck('service_id')->filter()->unique()->values()->all();
        $statuses=Service::whereIn('id',$services)->pluck('status','id');
        $cancel=NativeCancellation::withIntents(ServiceCancellation::whereIn('service_id',$services)->get(),$services,true);
        $adoptionIds=$domains->pluck('adoption_id')->filter()->unique()->values()->all();
        $adoptions=$adoptionIds?Adoption::whereIn('id',$adoptionIds)->get()->keyBy('id'):collect();
        $invoices=Invoice::whereIn('id',$domains->map(fn(Domain $d)=>$d->latestAcquisition?->invoice_id)->filter()->unique()->all())->get(['id','user_id','status'])->keyBy('id');
        $waiting=Operation::query()->whereIn('domain_id',$ids)->whereIn('state',[...StaffDomainStatus::MOVING,'held','uncertain'])->orderBy('id')->get()->keyBy('domain_id');
        $accounts=$release?['accounts'=>self::accounts([...$domains->all(),...$domains->pluck('latestAcquisition')->filter()->all(),...$operations->flatten(1)->all(),...$waiting->all()])]:null;
        $inputs=[];
        foreach ($domains as $d) {
            $ops=$operations[$d->id]??collect(); $rows=$open[$d->id]??collect(); $current=RenewalCycles::current($d,$ops)->last();
            $replaced=$current!==null&&$current->safe_code!=='renewal_cancelled_unpaid';
            $stops=$cancel->where('service_id',$d->service_id)->values();
            try { $off=RenewalStop::stopped(['domain'=>$d,'adoption'=>$adoptions->get($d->adoption_id),'cancel'=>$stops]); } catch (\Throwable) { $off=null; }
            $invoice=$invoices->get($d->latestAcquisition?->invoice_id);
            $live=$rows->reject(fn(object $a)=>$a->code==='renewal_unpaid'&&$replaced||$a->code==='observation_review'&&DomainFacts::ended($d)
                ||ObligationAlerts::outdated($a,$a->operation_id===null?null:$ops->firstWhere('id',(int)$a->operation_id),$ops,$d));
            $inputs[$d->id]=['alerts'=>$live->map(fn(object $a)=>['code'=>$a->code,'request'=>$a->operation_id===null?null:(int)$a->operation_id])->values()->all(),
                'late'=>$rows->contains('code','acquisition_late_money'),
                'renewal'=>$current&&in_array($current->state,self::OPEN_RENEWAL,true)?$current:null,'off'=>$off,
                'invoice'=>(int)$invoice?->user_id===(int)$d->user_id?$invoice:null,'closed'=>OwnedDomains::closed($statuses->get($d->service_id),$stops->isNotEmpty()),'waiting'=>$waiting->get($d->id),
                'orders_off'=>$queued->has($d->getKey())&&OperationQueries::ordersOff($d->latestAcquisition,$ordersOff),'accounts'=>$ordersOff,
                ...($accounts?['release'=>$accounts]:[])];
        }
        return $inputs;
    }
    /** The registrar account of each domain and request, in one query, keyed by OperationQueries::accountKey. A missing account maps to null.
     * @param list<Domain|Operation> $rows
     * @return array<string,?Account> */
    private static function accounts(array $rows): array
    {
        $keys=[];
        foreach ($rows as $r) { $keys[OperationQueries::accountKey($r->server_id,$r->registrar_key,$r->account_fingerprint)]=[$r->server_id,$r->registrar_key,$r->account_fingerprint]; }
        if (!$keys) { return []; }
        $found=Account::query()->where(function ($q) use ($keys): void {
            foreach ($keys as [$server,$registrar,$fingerprint]) {
                $q->orWhere(fn($w)=>$w->where('server_id',$server)->where('registrar_key',$registrar)->where('account_fingerprint',$fingerprint));
            }
        })->orderBy('id')->get();
        $accounts=array_fill_keys(array_keys($keys),null);
        foreach ($found as $a) { $accounts[OperationQueries::accountKey($a->server_id,$a->registrar_key,$a->account_fingerprint)]??=$a; }
        return $accounts;
    }
}
