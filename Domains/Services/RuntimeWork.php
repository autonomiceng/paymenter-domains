<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** Sole scheduler authority. Bounded durable account turns; each financial authority rechecks its own locks. */
final readonly class RuntimeWork
{
    public function __construct(private Clock $clock,private RuntimePorts $ports) {}
    public function service(int $service): void
    {foreach(Operation::where('service_id',$service)->whereIn('state',['queued','awaiting_payment','awaiting_code'])->get() as $op){
        // Sync CreateJob can arrive before the native Active save and before Invoice Paid.
        if($op->action==='transfer'){$this->transferPayment($op);}
        $this->enqueue((int)$op->id,true);
    }}
    public function invoice(int $invoice): void
    {
        foreach(Operation::where('invoice_id',$invoice)->orderBy('id')->get() as $op){$this->attempt('late_money',fn()=>app(AcquisitionRetirement::class)->inspect((int)$op->domain_id),(int)$op->id);if($op->action==='transfer'){$this->transferPayment($op);}$this->attempt('invoice_dispatch',fn()=>$this->enqueue((int)$op->id,true),(int)$op->id);$this->notice((int)$op->domain_id);}
    }
    private function enqueue(int $id,bool $force=false): void {app(RuntimeDispatch::class)->enqueue($id,$force);}
    public function operation(int $id): void
    {
        $op=Operation::findOrFail($id);try{$a=Account::where('server_id',$op->server_id)->first();$pausedTest=$a&&!$a->processing_enabled&&$op->action==='register'&&$op->state==='awaiting_payment'
            &&\App\Models\Invoice::whereKey($op->invoice_id)->value('status')==='paid'&&self::testRegistrationEnabled($a);
            if($pausedTest){if(app(RuntimeDispatch::class)->current($op)){app(OperationClaims::class)->holdUnclaimed((int)$op->id,'processing');}return;}
            if($a&&($a->processing_enabled||in_array($op->state,['pending','uncertain','running'],true))&&$this->ports->accepted($a)){if(app(RuntimeDispatch::class)->current($op)){$this->ports->operation($op);}}}finally{$this->notice((int)$op->domain_id);}
    }
    private static function testRegistrationEnabled(Account $account): bool
    {try{return EnomTestRegistration::enabled(CatalogueAcceptances::binding($account));}catch(\Throwable){return false;}}
    private function notice(int $id): void {$this->attempt('notices',fn()=>app(Reminders::class)->remind($id),$id);}
    private function attempt(string $producer,\Closure $run,?int $row=null): void
    {(new RuntimeDiagnostics($this->clock))->attempt($producer,$run,$row);}
    private function transferPayment(Operation $op): void
    {
        $this->attempt('transfer_payment',function()use($op):void {
            if($reason=RuntimeCandidates::transferReason($op)){throw new RuntimeIneligible($reason);}
            app(TransferInputs::class)->paymentAccepted((int)$op->id);
        },(int)$op->id);
    }
    private function bill(Domain $d): void
    {
        $this->attempt('renewal_expiry',fn()=>app(RenewalCycles::class)->expireUnpaid((int)$d->id),(int)$d->id);
        $this->attempt('billing',function()use($d):void {
            if($reason=RuntimeCandidates::billingReason($d)){throw new RuntimeIneligible($reason);}
            app(DomainBilling::class)->invoice((int)$d->id);
        },(int)$d->id);
    }
    public function tick(): void { $this->turn(false); }
    /** Dispatch existing operations only. The full scheduler must be off when using this entry point. */
    public function tickOperations(): void { $this->turn(true); }
    private function turn(bool $operationsOnly): void
    {
        OperationClaims::outside();if(!Schema::hasTable('domains_runtime_turns')){return;}
        // Producer enrollment can be absent after an operations-only deployment.
        if(!$operationsOnly){
            foreach(Account::where(fn($q)=>$q->whereNotIn('server_id',DB::table('domains_reminder_schedules')->select('server_id'))
                ->orWhereNotIn('server_id',DB::table('domains_observation_queues')->select('server_id')))->orderBy('server_id')->limit(8)->get() as $a){
                $this->attempt('enrollment',function()use($a):void {ReminderSchedules::enroll((int)$a->server_id);app(ObservationWork::class)->enroll((int)$a->server_id);},(int)$a->server_id);
            }
        }
        foreach(Account::whereNotIn('server_id',DB::table('domains_runtime_turns')->select('server_id'))->orderBy('server_id')->limit(8)->get() as $a){
            $this->attempt('enrollment',function()use($a):void {
                DB::table('domains_runtime_turns')->insertOrIgnore(['server_id'=>$a->server_id,'account_fingerprint'=>$a->account_fingerprint]);
            },(int)$a->server_id);
        }
        $balanceAccounts=[];
        // Advance only the selected turn, before work: one failed account cannot pin all other accounts.
        for($turn=0;$turn<8;$turn++){
            $row=DB::transaction(function(){
                $q=DB::table('domains_runtime_turns');$r=(clone $q)->where('due_at','<=',$this->clock->now()->getTimestamp())->orderBy('turn')->orderBy('server_id')->lockForUpdate()->first();
                if($r){(clone $q)->where('id',$r->id)->update(['turn'=>(int)$q->max('turn')+1,'due_at'=>$this->clock->now()->getTimestamp()+60]);}return $r;
            });if(!$row){break;}
            $a=Account::where('server_id',$row->server_id)->first();
            if(!$a||$a->account_fingerprint!==$row->account_fingerprint){continue;}
            if(!$operationsOnly){$balanceAccounts[]=$a;}
            $ops=Operation::where('server_id',$a->server_id)->where('account_fingerprint',$a->account_fingerprint)->where('id','>',$row->operation_cursor)
                ->whereIn('state',['awaiting_payment','awaiting_code','queued','pending','uncertain','running'])->orderBy('id')->limit(25)->get();
            foreach($ops as $op){if($op->action==='transfer'){$this->transferPayment($op);}$this->attempt('operation',fn()=>$this->enqueue((int)$op->id),(int)$op->id);}
            if($operationsOnly){
                DB::table('domains_runtime_turns')->where('id',$row->id)->update(['operation_cursor'=>$ops->count()===25?$ops->last()->id:0,'finished_at'=>$this->clock->now()->getTimestamp()]);
                continue;
            }
            $domains=Domain::where('server_id',$a->server_id)->where('id','>',$row->domain_cursor)->orderBy('id')->limit(25)->get();
            foreach($domains as $d){if(in_array($d->provenance,[null,'purchased','converted_purchase'],true)){$this->attempt('transfer_watch_enrollment',fn()=>(new TransferWatchEnrollment())->repair((int)$d->id,(int)$d->user_id,$this->clock->now()->getTimestamp()),(int)$d->id);}if($d->provenance==='adopted'){$this->attempt('adoption_enrollment',fn()=>app(AdoptionEnrollment::class)->repair((int)$d->id),(int)$d->id);}$this->attempt('acquisition_retirement',fn()=>app(AcquisitionRetirement::class)->inspect((int)$d->id),(int)$d->id);$this->attempt('native_identity',fn()=>NativeIdentityDiscrepancies::inspect($d,$this->clock->now()->getTimestamp()),(int)$d->id);$this->bill($d);}
            $tlds=\Paymenter\Extensions\Servers\Domains\Models\Tld::where('server_id',$a->server_id)->where('id','>',$row->tld_cursor)->orderBy('id')->limit(25)->get();
            $tldCursor=(int)$row->tld_cursor;
            $this->attempt('publication',function()use($a,$tlds,&$tldCursor):void {
                if(!TestCatalogue::enabled(CatalogueAcceptances::binding($a))){
                    foreach($tlds as $t){$this->attempt('publication',fn()=>app(NativeCatalogue::class)->publish((int)$t->id,$this->clock->now()),(int)$t->id);}
                }
                // Complete the scan despite individual failures; wrapping retries every retained TLD.
                $tldCursor=$tlds->count()===25?$tlds->last()->id:0;
            },(int)$a->server_id);
            DB::table('domains_runtime_turns')->where('id',$row->id)->update(['tld_cursor'=>$tldCursor,'operation_cursor'=>$ops->count()===25?$ops->last()->id:0,'domain_cursor'=>$domains->count()===25?$domains->last()->id:0,'finished_at'=>$this->clock->now()->getTimestamp()]);
        }
        if($operationsOnly){return;}
        $this->attempt('initial_managed_records',fn()=>$this->ports->initialManagedRecordObservations());
        foreach(ObservationCandidates::next('refund') as $ids){foreach($ids as $id){$this->attempt('refund',fn()=>app(Refunds::class)->creditRejected((int)$id),(int)$id);}}
        foreach(ObservationCandidates::next('timeout') as $ids){foreach($ids as $id){$this->attempt('timeout',fn()=>app(TransferInputs::class)->expireMissingCode((int)$id),(int)$id);}}
        $this->attempt('reminders',fn()=>app(Reminders::class)->sweep());
        $this->attempt('watch_public_checks',fn()=>app(WatchWork::class)->tick());
        // Dispatch every selected account and independent producer before optional registrar I/O.
        foreach($balanceAccounts as $a){
            $this->attempt('registrar_balance',function()use($a):void {
                $binding=CatalogueAcceptances::binding($a);
                $test=EnomTestRegistration::enabled($binding)||EnomTestManagement::enabled($binding)||EnomTestTransfer::enabled($binding)||EnomTestRenewal::enabled($binding);
                if(!$test){app(RegistrarBalances::class)->daily($a);}
            },(int)$a->server_id);
            $this->attempt('price_sync',fn()=>app(PriceSync::class)->daily($a),(int)$a->server_id);
        }
    }
}
