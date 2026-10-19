<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,InvoiceItem,InvoiceTransaction,Service,ServiceCancellation};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** Scoped native write guards. No provider calls and no transaction spans two model events. */
final class NativeCancellation
{
    public const MESSAGE="This domain can't be cancelled or suspended while its registration or transfer is still being processed. Check its status on the Domains page.";
    public static function boot(): void
    {
        static $booted=false;if($booted){return;}$booted=true;
        // Creation and ordinary reassignment are guarded. Privileged numeric reassignment is
        // unsupported: Eloquent passes extras to SQL after events, concealing arithmetic targets.
        foreach(['creating','updating']as$event){ServiceCancellation::$event(static function(ServiceCancellation $c):void {
            $ids=[...array_column(NativeIdentity::rows($c),'service_id'),...self::values($c->getAttributes(),'service_id')];
            foreach(array_unique(array_filter($ids))as$id){self::guard((int)$id,'cancellation');}
        });}
        Service::updating(static function(Service $s):void {
            // Active is native paid billing state, regardless of caller or registrar progress.
            $unsafe=['cancelled','suspended'];$attrs=$s->getAttributes();$aliases=$attrs;unset($aliases['status']);
            $stored=Service::find($s->getRawOriginal('id')??$s->id)?->status;
            $proposed=array_map('strtolower',self::values($attrs,'status'));
            if(NativeDomainGuard::identityProjection($s)
                ||(!NativeIdentity::dirty($s,['status'])&&!array_diff(array_intersect($proposed,$unsafe),[(string)$stored])
                    &&!array_intersect(array_map('strtolower',self::values($aliases,'status')),$unsafe))
                ||!array_intersect($proposed,$unsafe)){return;}
            foreach(array_unique(array_filter(array_column(NativeIdentity::rows($s),'id')))as$id){self::guard((int)$id,'status');}
        });
    }
    /** Add owned intent to cancellation checks without inventing a native cancellation row. */
    public static function withIntents(Collection $cancel,array $serviceIds,bool $advisory=false): Collection
    {
        if(!Schema::hasTable('domains_native_intents')){return $cancel;}
        $rows=\Paymenter\Extensions\Servers\Domains\Models\NativeIntent::whereIn('service_id',$serviceIds)->orderBy('service_id')
            ->when(!$advisory,fn($q)=>$q->lockForUpdate())->get();
        return $cancel->toBase()->concat($rows);
    }
    private static function guard(int $id,string $kind): void
    {
        $seed=Service::find($id);if(!$seed||!NativeIdentity::routed($seed)){return;}
        if(!Schema::hasTable('domains_native_intents')){
            self::refuse('Cancelling or suspending this domain is unavailable until the Domains update is finished. Contact support if this continues.',(int)$seed->id,$kind);
        }
        // Invoice prefix before all orders/services, then owned intents/domains/operations. We
        // never acquire a user lock after a native cron caller already locked its invoices.
        $write=static function()use($seed,$kind):void {
            $invoiceIds=InvoiceItem::where('reference_type',$seed->getMorphClass())->where('reference_id',$seed->id)->pluck('invoice_id')
                ->merge(Operation::where('service_id',$seed->id)->whereNotNull('invoice_id')->pluck('invoice_id'))->unique()->sort()->values();
            $invoices=Invoice::whereKey($invoiceIds)->orderBy('id')->lockForUpdate()->get();
            $items=InvoiceItem::whereIn('invoice_id',$invoiceIds)->orderBy('id')->lockForUpdate()->get();
            $money=InvoiceTransaction::whereIn('invoice_id',$invoiceIds)->orderBy('id')->lockForUpdate()->get();
            [, $services]=HoldingNativeLocks::lockServices($items->where('reference_type',$seed->getMorphClass())->pluck('reference_id')->push($seed->id)->unique()->all());
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
                // Require its exact current billing anchor and MAC, never a succeeded label.
                foreach($domains as$d){
                    $anchor=$ops->firstWhere('operation_key',$d->renewal_policy['billing_anchor']['operation_key']??null);
                    $account=Account::where('server_id',$d->server_id)->first();
                    if($anchor&&$account&&ConvertedHoldingProof::completed($anchor,$d,CatalogueAcceptances::binding($account))){$allTerminal=true;}
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
            if(!$allTerminal){
                DB::table('domains_native_intents')->insertOrIgnore(['service_id'=>$seed->id,'kind'=>$kind,'created_at'=>now()]);
            }
        };
        if(DB::transactionLevel()===0){DB::transaction($write);}else{$write();}
    }
    private static function values(array $attrs,string $column): array
    {
        $values=[];foreach($attrs as$key=>$value){$parts=explode('.',explode('->',$key,2)[0]);if(strtolower(end($parts))===$column){$values[]=(string)$value;}}
        return $values;
    }
    private static function refuse(string $message,int $serviceId,string $kind): never
    { logger()->warning('Managed domain unsafe lifecycle write refused.',['service_id'=>$serviceId,'kind'=>$kind,'reason'=>$message]);throw \Illuminate\Validation\ValidationException::withMessages(['reason'=>$message,'status'=>$message,'data.reason'=>$message,'data.status'=>$message]); }
}
