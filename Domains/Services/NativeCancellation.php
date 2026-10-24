<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,InvoiceItem,InvoiceTransaction,Service,ServiceCancellation};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Adoption,Domain,Operation};
/** Scoped native write guards. No provider calls and no transaction spans two model events. */
final class NativeCancellation
{
    private static ?\WeakMap $adoptions = null;
    public const MESSAGE="This domain can't be cancelled or suspended while its registration or transfer is still being processed. Check its status on the Domains page.";
    public static function boot(): void
    {
        if(!HookRegistration::claim(Service::getEventDispatcher(),self::class)){return;}
        // Creation and ordinary reassignment are guarded. Privileged numeric reassignment is
        // unsupported: Eloquent passes extras to SQL after events, concealing arithmetic targets.
        foreach(['creating','updating']as$event){ServiceCancellation::$event(static function(ServiceCancellation $c):void {
            if (isset(self::$adoptions[$c])) {
                $expected = self::$adoptions[$c];
                if ($c->exists || $c->getTable() !== (new ServiceCancellation)->getTable()
                    || array_intersect_key($c->getAttributes(), $expected) !== $expected
                    || array_diff(array_keys($c->getAttributes()), ['service_id', 'type', 'reason', 'created_at', 'updated_at']) !== []) {
                    throw new \DomainException('Adoption cancellation changed during save.');
                }
                return;
            }
            if ($c->exists && $c->isDirty()) { self::retained($c); }
            $ids=[...array_column(NativeIdentity::rows($c),'service_id'),...self::values($c->getAttributes(),'service_id')];
            foreach(array_unique(array_filter($ids))as$id){self::guard((int)$id,'cancellation',$c);}
        });}
        ServiceCancellation::deleting(static function(ServiceCancellation $c):void {
            self::retained($c);
        });
        Service::updating(static function(Service $s):void {
            // Active is native paid billing state, regardless of caller or registrar progress.
            $unsafe=['cancelled','suspended'];$attrs=$s->getAttributes();$aliases=$attrs;unset($aliases['status']);
            $stored=Service::find($s->getRawOriginal('id')??$s->id)?->status;
            $proposed=array_map('strtolower',self::values($attrs,'status'));
            if(NativeDomainGuard::identityProjection($s)
                ||(!NativeIdentity::dirty($s,['status'])&&!array_diff(array_intersect($proposed,$unsafe),[(string)$stored])
                    &&!array_intersect(array_map('strtolower',self::values($aliases,'status')),$unsafe))
                ||!array_intersect($proposed,$unsafe)){return;}
            foreach(array_unique(array_filter(array_column(NativeIdentity::rows($s),'id')))as$id){self::guard((int)$id,'status',closing:strtolower((string)$s->status)==='cancelled');}
        });
    }
    /** Exact one-row public model write under the retained native prefix; no general callback grant. */
    public static function forAdoption(HoldingIdentityLock $lock, \Paymenter\Extensions\Servers\Domains\Models\Adoption $a): ?int
    {
        $n = $lock->nativeRows((int) $a->user_id); $service = $n['service'];
        $rows = $n['cancel']->where('service_id', $service->id);
        if ($rows->count() > 1 || $rows->contains(fn ($c) => !($c instanceof ServiceCancellation) || $c->type !== 'end_of_period')) {
            throw new \DomainException('Conflicting domain cancellation requires review.');
        }
        if (!$a->do_not_renew) {
            if ($rows->isNotEmpty()) { throw new \DomainException('Cancellation intent changed. Verify again.'); }
            return null;
        }
        if ($a->state !== 'preview' || (int) $a->service_id !== (int) $service->id
            || ($a->evidence['do_not_renew'] ?? null) !== true || $service->expires_at !== null) {
            throw new \DomainException('Verified adoption cancellation required.');
        }
        if ($rows->isNotEmpty()) { return (int) $rows->sole()->id; }
        $attrs = ['service_id' => (int) $service->id, 'type' => 'end_of_period', 'reason' => $a->reason];
        $c = new ServiceCancellation($attrs); self::$adoptions ??= new \WeakMap; self::$adoptions[$c] = $attrs;
        try {
            $c->save(); $saved = ServiceCancellation::findOrFail($c->id);
            foreach ($attrs as $key => $value) { if ($saved->$key !== $value) { throw new \DomainException('Adoption cancellation changed during save.'); } }
            $lock->owners((int) $a->user_id);
            return (int) $saved->id;
        } finally { unset(self::$adoptions[$c]); }
    }
    /** Add owned intent to cancellation checks without inventing a native cancellation row. */
    public static function withIntents(Collection $cancel,array $serviceIds,bool $advisory=false): Collection
    {
        if(!Schema::hasTable('domains_native_intents')){return $cancel;}
        $rows=\Paymenter\Extensions\Servers\Domains\Models\NativeIntent::whereIn('service_id',$serviceIds)->orderBy('service_id')
            ->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        return $cancel->toBase()->concat($rows);
    }
    private static function guard(int $id,string $kind,?ServiceCancellation $cancellation=null,bool $closing=false): void
    {
        $seed=Service::find($id);if(!$seed||!NativeIdentity::routed($seed)){return;}
        if(!Schema::hasTable('domains_native_intents')){
            self::refuse('Can’t cancel this domain right now. Contact support.',(int)$seed->id,$kind);
        }
        // Invoice prefix before all orders/services, then owned intents/domains/operations. We
        // never acquire a user lock after a native cron caller already locked its invoices.
        $write=static function()use($seed,$kind,$cancellation,$closing):void {
            $invoiceIds=InvoiceItem::where('reference_type',$seed->getMorphClass())->where('reference_id',$seed->id)->pluck('invoice_id')
                ->merge(Operation::where('service_id',$seed->id)->whereNotNull('invoice_id')->pluck('invoice_id'))->unique()->sort()->values();
            $invoices=Invoice::whereKey($invoiceIds)->orderBy('id')->lockForUpdate()->get();
            $items=InvoiceItem::whereIn('invoice_id',$invoiceIds)->orderBy('id')->lockForUpdate()->get();
            $money=InvoiceTransaction::whereIn('invoice_id',$invoiceIds)->orderBy('id')->lockForUpdate()->get();
            [$orders, $services]=HoldingNativeLocks::lockServices($items->where('reference_type',$seed->getMorphClass())->pluck('reference_id')->push($seed->id)->unique()->all());
            $service=$services->firstWhere('id',$seed->id);
            if(!$service){self::refuse('This domain changed while you were saving. Reload the page and try again.',(int)$seed->id,$kind);}
            DB::table('domains_native_intents')->where('service_id',$seed->id)->lockForUpdate()->get();
            $domains=Domain::where('service_id',$seed->id)->orderBy('id')->lockForUpdate()->get();
            $ops=Operation::where('service_id',$seed->id)->orderBy('id')->lockForUpdate()->get();
            if($ops->whereNotNull('invoice_id')->pluck('invoice_id')->diff($invoiceIds)->isNotEmpty()){
                self::refuse('This domain changed while you were saving. Reload the page and try again.',(int)$seed->id,$kind);
            }
            $acquisitions=$ops->whereIn('action',['register','transfer']);$allTerminal=$acquisitions->isNotEmpty();
            if($acquisitions->isEmpty()){
                // A converted holding may retain only a converter-sealed historical renewal.
                // Require its authenticated current billing anchor, including later renewals; never a state label.
                foreach($domains as$d){
                    $anchor=$ops->firstWhere('operation_key',$d->renewal_policy['billing_anchor']['operation_key']??null);
                    $account=Account::where('server_id',$d->server_id)->first();
                    if($anchor&&$account&&RenewalAuthority::completed($anchor,$d,CatalogueAcceptances::binding($account))){$allTerminal=true;}
                }
            }
            // A stop grants no spending or mutation authority. Accept the original immutable
            // adoption root for end-of-period cancellation, never immediate termination.
            if ($kind === 'cancellation' && $cancellation?->type === 'end_of_period') {
                foreach ($domains as $d) {
                    $a = $d->adoption_id ? Adoption::whereKey($d->adoption_id)->lockForUpdate()->first() : null;
                    $n = $a?->evidence['native_identity'] ?? [];
                    $order = $orders->firstWhere('id', $service->order_id);
                    if ($a && $a->state === 'committed' && (int) $a->domain_id === (int) $d->id
                        && $d->provenance === 'adopted' && $d->billing_managed && $service->expires_at === null
                        && (int) $d->user_id === (int) $service->user_id && (int) $order?->user_id === (int) $d->user_id
                        && ($n['service_id'] ?? null) === (int) $service->id && ($n['order_id'] ?? null) === (int) $service->order_id
                        && ($n['product_id'] ?? null) === (int) $service->product_id && ($n['annual_plan_id'] ?? null) === (int) $service->plan_id
                        && hash_equals($a->request_digest, AdoptionMapping::stamp($a->evidence))
                        && ($d->snapshot['holding']['request_digest'] ?? null) === $a->request_digest) { $allTerminal = true; }
                }
            }
            foreach($acquisitions as$op){
                $d=$domains->firstWhere('id',$op->domain_id);$account=$d?Account::where('server_id',$d->server_id)->first():null;
                $terminal=$d&&$account&&RenewalAuthority::resolved($op,$d,CatalogueAcceptances::binding($account));
                $allTerminal=$allTerminal&&$terminal;
                $paid=$invoices->firstWhere('id',$op->invoice_id)?->status==='paid'
                    ||$money->where('invoice_id',$op->invoice_id)->contains(fn($t)=>in_array($t->status->value,['succeeded','processing'],true));
                $attempted=($op->attempt??[])!==[]||$op->started_at!==null||$op->request_fingerprint!==null
                    ||$op->lease_expires_at!==null||$op->provider_order_id!==null||$op->confirmed_expires_at!==null;
                if(!$terminal&&($paid||$attempted)){self::refuse(self::MESSAGE,(int)$seed->id,$kind);}
            }
            if($acquisitions->isEmpty()&&!$allTerminal
                &&($service->status==='active'||$invoices->contains('status','paid')||$money->contains(fn($t)=>in_array($t->status->value,['succeeded','processing'],true)))){
                self::refuse(self::MESSAGE,(int)$seed->id,$kind); // A missing ledger is not terminal proof.
            }
            // The committed intent closes the creating-check/INSERT gap. Never clear it on a
            // timeout or staff acknowledgement: the paused native request could still insert.
            // An unpaid pending service has no paid period to finish, including a closed ledger.
            if($cancellation&&$service->status==='pending'&&!$invoices->contains('status','paid')){$cancellation->type='immediate';}
            // Keep closure evidence at the guarded decision, before a lost after-commit wake
            // or native re-payment can erase the observable Cancelled state.
            if($closing){foreach($acquisitions as$op){
                AcquisitionRetirement::retainClosure($op,$money->where('invoice_id',$op->invoice_id),
                    $invoices->firstWhere('id',$op->invoice_id)?->status,now(),cancelled:true);
            }}
            if(!$allTerminal){
                DB::table('domains_native_intents')->insertOrIgnore(['service_id'=>$seed->id,'kind'=>$kind,'created_at'=>now()]);
            }
        };
        if(DB::transactionLevel()===0){DB::transaction($write);}else{$write();}
    }
    private static function retained(ServiceCancellation $c): void
    {
        if (!Schema::hasTable('domains_adoptions')) { return; }
        $a = Adoption::where('state', 'committed')->whereIn('cancellation_id', array_filter([$c->getRawOriginal('id'), $c->id]))->first();
        if (!$a) { return; }
        logger()->warning('Original adoption cancellation change refused.', ['adoption_id' => $a->id, 'service_id' => $a->service_id]);
        throw \Illuminate\Validation\ValidationException::withMessages(['reason' => 'This cancellation records the original adoption renewal stop and must be retained. Review the domain adoption history.']);
    }
    private static function values(array $attrs,string $column): array
    {
        $values=[];foreach($attrs as$key=>$value){$parts=explode('.',explode('->',$key,2)[0]);if(strtolower(end($parts))===$column){$values[]=(string)$value;}}
        return $values;
    }
    private static function refuse(string $message,int $serviceId,string $kind): never
    { logger()->warning('Managed domain unsafe lifecycle write refused.',['service_id'=>$serviceId,'kind'=>$kind,'reason'=>$message]);throw \Illuminate\Validation\ValidationException::withMessages(['reason'=>$message,'status'=>$message,'data.reason'=>$message,'data.status'=>$message]); }
}
