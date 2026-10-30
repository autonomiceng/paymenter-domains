<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\{Invoice, Service, ServiceCancellation};
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{NativeCancellation, ObligationAlerts, OwnedDomains, RenewalCycles, RenewalStop};
/** What the staff status and the Registration card read beyond the domain row, in one batch per page.
 * Hides nothing from history: the alert rows stay until an inspection closes them. */
final class StaffDomainInputs
{
    /** Renewal request states that still have a next step. */
    private const OPEN_RENEWAL=['awaiting_payment','queued','running','pending','held','uncertain'];
    /** @param iterable<Domain> $domains
     * @return array<int,array{alerts:list<array{code:string,request:?int}>,late:bool,renewal:?Operation,off:?bool,invoice:?Invoice,closed:bool}> by domain id.
     * off: automatic renewal is off, null when its saved data can't be read. invoice and closed: what OwnedDomains::order reads. */
    public static function for(iterable $domains): array
    {
        $domains=collect($domains); $ids=$domains->pluck('id')->all();
        $open=Schema::hasTable('domains_obligation_alerts') ? DB::table('domains_obligation_alerts')->whereIn('domain_id',$ids)->whereNull('resolved_at')
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
        $inputs=[];
        foreach ($domains as $d) {
            $ops=$operations[$d->id]??collect(); $rows=$open[$d->id]??collect(); $current=RenewalCycles::current($d,$ops)->last();
            $replaced=$current!==null&&$current->safe_code!=='renewal_cancelled_unpaid';
            $stops=$cancel->where('service_id',$d->service_id)->values();
            try { $off=RenewalStop::stopped(['domain'=>$d,'adoption'=>$adoptions->get($d->adoption_id),'cancel'=>$stops]); } catch (\Throwable) { $off=null; }
            $invoice=$invoices->get($d->latestAcquisition?->invoice_id);
            $live=$rows->reject(fn(object $a)=>$a->code==='renewal_unpaid'&&$replaced
                ||ObligationAlerts::outdated($a,$a->operation_id===null?null:$ops->firstWhere('id',(int)$a->operation_id),$ops,$d));
            $inputs[$d->id]=['alerts'=>$live->map(fn(object $a)=>['code'=>$a->code,'request'=>$a->operation_id===null?null:(int)$a->operation_id])->values()->all(),
                'late'=>$rows->contains('code','acquisition_late_money'),
                'renewal'=>$current&&in_array($current->state,self::OPEN_RENEWAL,true)?$current:null,'off'=>$off,
                'invoice'=>(int)$invoice?->user_id===(int)$d->user_id?$invoice:null,'closed'=>OwnedDomains::closed($statuses->get($d->service_id),$stops->isNotEmpty())];
        }
        return $inputs;
    }
}
