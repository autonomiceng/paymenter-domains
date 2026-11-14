<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
/** Durable poll backoff survives queue restarts; unique jobs bound worker backlog. */
final readonly class RuntimeDispatch
{
    public function __construct(private Clock $clock) {}
    public function current(Operation $op): bool
    {
        ConversionGate::requireComplete();$d=Domain::find($op->domain_id);$a=Account::where('server_id',$op->server_id)->first();
        if ($d && $op->action === 'renew' && in_array($op->state, ['awaiting_payment', 'queued'], true) && RenewalCycles::registrarVirgin($op) && RenewalStop::adopted(RenewalStop::read($d))) { ObligationAlerts::record($d, 'operation_review', $this->clock->now()->getTimestamp(), (int) $op->id); return false; }
        if($d&&!$d->adoptionPending()&&!($op->attempt['legacy_retained']??false)&&$a&&$a->account_fingerprint===$op->account_fingerprint&&$d->account_fingerprint===$op->account_fingerprint&&$d->authority_generation===$op->authority_generation){return true;}
        if($d){ObligationAlerts::record($d,'operation_review',$this->clock->now()->getTimestamp(),(int)$op->id);}return false;
    }
    public function enqueue(int $id,bool $force=false): void
    {
        $op=Operation::findOrFail($id);
        ContactRecovery::retryAccount($id,$this->clock);$op=$op->fresh();
        $balance=BalanceRetries::pending($op);
        if(!$this->current($op)){if($balance){app(OperationClaims::class)->readmitBalance($id);}return;}
        if(OperationClaims::ordersOffHold($op)){app(OperationClaims::class)->readmitOrdersOff($id);$op=$op->fresh();}
        if(ContactStepRetries::candidate($op)){ContactStepRetries::readmit($id,$this->clock);$op=$op->fresh();}
        if($op->state==='held'&&!ManagementEvidence::recoverable($op)&&!RenewalRetries::candidate($op)&&!TransferPolling::applies($op)&&(!$balance||!Account::where('server_id',$op->server_id)->value('processing_enabled'))){return;}
        $now=$this->clock->now()->getTimestamp();
        if(!TransferPolling::due($op,$now)){return;}
        $due=DB::transaction(function()use($id,$op,$now,$force,$balance):bool {
            DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$id,...($balance?['due_at'=>$now+900]:[])]);
            $q=DB::table('domains_runtime_operations')->where('operation_id',$id);$row=(clone $q)->lockForUpdate()->first();
            // Input may have committed while dispatch waited for the runtime row. Read without taking another lock.
            $op=$op->fresh();$balance=BalanceRetries::pending($op);
            if(!TransferPolling::due($op,$now)){return false;}
            // TransferPolling owns transfer pacing; balance readmission retains its own due entry.
            if(($balance||!$force&&!TransferPolling::applies($op))&&(int)$row->due_at>$now){return false;}
            if($balance){return true;} // The locked readmission consumes due_at, never a queued notification.
            $slowManagement=isset($op->policy['management'])&&in_array($op->state,['uncertain','running'],true)&&ManagementReadRecovery::exhausted($op->attempt);
            if($slowManagement){
                $ready=ManagementReadRecovery::last($op->attempt)?->getTimestamp();
                if($ready!==null&&$ready+21600>$now){$q->update(['due_at'=>$ready+21600]);return false;}
            }
            $poll=in_array($op->state,['pending','uncertain','running'],true)||TransferPolling::applies($op);$attempts=$poll?(int)$row->attempts+1:0;
            $waiting=$op->state==='held'||$op->state==='awaiting_payment'&&\App\Models\Invoice::whereKey($op->invoice_id)->value('status')!=='paid'||$op->state==='awaiting_code'&&!TransferInputs::validCode($op->payload['epp']??null);
            $delay=$slowManagement?21600:($poll?min(21600,300*(2**min(7,$attempts-1))):($waiting?900:60));
            if(TransferPolling::applies($op)){$delay=60;}
            $q->update(['attempts'=>$attempts,'due_at'=>$now+$delay]);return true;
        });
        if($due){\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::dispatch($id)->afterCommit();}
    }
}
