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
        $d=Domain::find($op->domain_id);$a=Account::where('server_id',$op->server_id)->first();
        if($d&&$a&&$a->account_fingerprint===$op->account_fingerprint&&$d->account_fingerprint===$op->account_fingerprint&&$d->authority_generation===$op->authority_generation){return true;}
        if($d){ObligationAlerts::record($d,'operation_review',$this->clock->now()->getTimestamp(),(int)$op->id);}return false;
    }
    public function enqueue(int $id,bool $force=false): void
    {
        $op=Operation::findOrFail($id);if(!$this->current($op)){return;}$now=$this->clock->now()->getTimestamp();
        $due=DB::transaction(function()use($id,$op,$now,$force):bool {
            DB::table('domains_runtime_operations')->insertOrIgnore(['operation_id'=>$id]);
            $q=DB::table('domains_runtime_operations')->where('operation_id',$id);$row=(clone $q)->lockForUpdate()->first();
            if(!$force&&(int)$row->due_at>$now){return false;}
            $poll=in_array($op->state,['pending','uncertain','running'],true);$attempts=$poll?(int)$row->attempts+1:0;
            $waiting=$op->state==='awaiting_payment'&&\App\Models\Invoice::whereKey($op->invoice_id)->value('status')!=='paid'||$op->state==='awaiting_code'&&!TransferInputs::validCode($op->payload['epp']??null);
            $delay=$poll?min(21600,300*(2**min(7,$attempts-1))):($waiting?900:60);
            $q->update(['attempts'=>$attempts,'due_at'=>$now+$delay]);return true;
        });
        if($due){\Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::dispatch($id)->afterCommit();}
    }
}
