<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Invoice,Server};
use Illuminate\Support\Facades\{DB,Log,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** Sole scheduler authority. Bounded durable account turns; each financial authority rechecks its own locks. */
final readonly class RuntimeWork
{
    public function __construct(private Clock $clock,private RuntimePorts $ports) {}
    public function service(int $service): void
    {foreach(Operation::where('service_id',$service)->whereIn('state',['queued','awaiting_payment','awaiting_code'])->get() as $op){
        // Sync CreateJob can arrive before the native Active save and before Invoice Paid.
        if($op->action==='transfer'){$this->attempt('transfer_payment',fn()=>app(TransferInputs::class)->paymentAccepted((int)$op->id));}
        $this->enqueue((int)$op->id,true);
    }}
    public function invoice(int $invoice): void
    {
        foreach(Operation::where('invoice_id',$invoice)->orderBy('id')->get() as $op){$this->attempt('late_money',fn()=>app(AcquisitionRetirement::class)->inspect((int)$op->domain_id));if($op->action==='transfer'){$this->attempt('transfer_payment',fn()=>app(TransferInputs::class)->paymentAccepted((int)$op->id));}$this->attempt('invoice_dispatch',fn()=>$this->enqueue((int)$op->id,true));$this->notice((int)$op->domain_id);}
    }
    private function enqueue(int $id,bool $force=false): void {app(RuntimeDispatch::class)->enqueue($id,$force);}
    public function operation(int $id): void
    {
        $op=Operation::findOrFail($id);try{$a=Account::where('server_id',$op->server_id)->first();if($a&&($a->processing_enabled||in_array($op->state,['pending','uncertain','running'],true))&&$this->ports->accepted($a)){if(app(RuntimeDispatch::class)->current($op)){$this->ports->operation($op);}}}finally{$this->notice((int)$op->domain_id);}
    }
    private function notice(int $id): void {$this->attempt('notices',fn()=>app(Reminders::class)->remind($id));}
    private function attempt(string $producer,\Closure $run): void
    {try{$run();}catch(\Throwable $e){Log::warning('Domains runtime work held.',['producer'=>$producer,'exception_class'=>$e::class]);}}
    public function tick(): void
    {
        OperationClaims::outside();if(!Schema::hasTable('domains_runtime_turns')){return;}
        foreach(Account::whereNotIn('server_id',DB::table('domains_runtime_turns')->select('server_id'))->orderBy('server_id')->limit(8)->get() as $a){
            $this->attempt('enrollment',function()use($a):void {
                ReminderSchedules::enroll((int)$a->server_id);app(ObservationWork::class)->enroll((int)$a->server_id);
                DB::table('domains_runtime_turns')->insertOrIgnore(['server_id'=>$a->server_id,'account_fingerprint'=>$a->account_fingerprint]);
            });
        }
        // Advance only the selected turn, before work: one failed account cannot pin all other accounts.
        for($turn=0;$turn<8;$turn++){
            $row=DB::transaction(function(){
                $q=DB::table('domains_runtime_turns');$r=(clone $q)->where('due_at','<=',$this->clock->now()->getTimestamp())->orderBy('turn')->orderBy('server_id')->lockForUpdate()->first();
                if($r){(clone $q)->where('id',$r->id)->update(['turn'=>(int)$q->max('turn')+1,'due_at'=>$this->clock->now()->getTimestamp()+60]);}return $r;
            });if(!$row){break;}
            $a=Account::where('server_id',$row->server_id)->first();
            if(!$a||$a->account_fingerprint!==$row->account_fingerprint){continue;}
            $this->attempt('registrar_balance',fn()=>app(RegistrarBalances::class)->daily($a));
            $ops=Operation::where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->where('id','>',$row->operation_cursor)
                ->whereIn('state',['awaiting_payment','awaiting_code','queued','pending','uncertain','running'])->orderBy('id')->limit(25)->get();
            foreach($ops as $op){if($op->action==='transfer'){$this->attempt('transfer_payment',fn()=>app(TransferInputs::class)->paymentAccepted((int)$op->id));}$this->attempt('operation',fn()=>$this->enqueue((int)$op->id));}
            $domains=Domain::where('server_id',$a->server_id)->where('id','>',$row->domain_cursor)->orderBy('id')->limit(25)->get();
            foreach($domains as $d){if(in_array($d->provenance,[null,'purchased','converted_purchase'],true)){$this->attempt('transfer_watch_enrollment',fn()=>(new TransferWatchEnrollment())->repair((int)$d->id,(int)$d->user_id,$this->clock->now()->getTimestamp()));}if($d->provenance==='adopted'){$this->attempt('adoption_enrollment',fn()=>app(AdoptionEnrollment::class)->repair((int)$d->id));}$this->attempt('acquisition_retirement',fn()=>app(AcquisitionRetirement::class)->inspect((int)$d->id));$this->attempt('native_identity',fn()=>NativeIdentityDiscrepancies::inspect($d,$this->clock->now()->getTimestamp()));$this->attempt('billing',fn()=>app(DomainBilling::class)->invoice((int)$d->id));}
            $tlds=\Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id',$a->server_id)->where('id','>',$row->tld_cursor)->orderBy('id')->limit(25)->get();
            foreach($tlds as $t){$this->attempt('publication',fn()=>app(NativeCatalogue::class)->publish((int)$t->id,$this->clock->now()));}
            DB::table('domains_runtime_turns')->where('id',$row->id)->update(['tld_cursor'=>$tlds->count()===25?$tlds->last()->id:0,'operation_cursor'=>$ops->count()===25?$ops->last()->id:0,'domain_cursor'=>$domains->count()===25?$domains->last()->id:0,'finished_at'=>$this->clock->now()->getTimestamp()]);
        }
        $this->attempt('initial_managed_records',fn()=>$this->ports->initialManagedRecordObservations());
        foreach(ObservationCandidates::next('refund') as $ids){foreach($ids as $id){$this->attempt('refund',fn()=>app(Refunds::class)->creditRejected((int)$id));}}
        foreach(ObservationCandidates::next('timeout') as $ids){foreach($ids as $id){$this->attempt('timeout',fn()=>app(TransferInputs::class)->expireMissingCode((int)$id));}}
        $this->attempt('reminders',fn()=>app(Reminders::class)->sweep());
        $this->attempt('watch_public_checks',fn()=>app(WatchWork::class)->tick());
        $this->attempt('public_suffix_list',fn()=>app(PublicSuffixList::class)->tick());
    }
}
