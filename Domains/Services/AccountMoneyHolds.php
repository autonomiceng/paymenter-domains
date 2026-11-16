<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Audit,Invoice,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Staff release is required because spent credit cannot distinguish fraud from a bank error. */
final class AccountMoneyHolds
{
    public static function detail(Domain $d): string
    {
        $details=[];
        foreach(DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','account_money_hold')->whereNull('resolved_at')->get() as $alert){
            $op=Operation::find($alert->operation_id);if(!$op){continue;}
            try{
                $e=self::evidence($op,$alert->alert_key);
                if(!$e){throw new \DomainException('Dispute evidence missing.');}
                $details[]='Dispute '.$e['dispute_id'].': lost $'.PricingRule::decimal($e['lost_minor']).', owed $'.PricingRule::decimal($e['owed_minor'])
                    .', removed $'.PricingRule::decimal($e['removed_minor']).', remainder $'.PricingRule::decimal($e['remainder_minor']).'.';
            }catch(\Throwable){$details[]='Dispute evidence needs a check on invoice #'.$op->invoice_id.'.';}
        }
        return implode(' ',$details);
    }
    private static function evidence(Operation $op,string $key): ?array
    {
        $result=null;
        foreach(Operation::where('invoice_id',$op->invoice_id)->orderBy('id')->get() as $peer){foreach($peer->funding['dispute_holds']??[] as $h){
            if(!hash_equals($key,DisputeRecoveries::key('account_money_hold',$h['dispute_id']))){continue;}
            $result??=['dispute_id'=>$h['dispute_id'],'lost_minor'=>$h['lost_minor'],'owed_minor'=>0,'removed_minor'=>0,'remainder_minor'=>0];
            foreach(['owed_minor','removed_minor','remainder_minor'] as $field){$result[$field]+=$h[$field];}
        }}
        if($result){return $result;}
        foreach(InvoiceMoney::project((int)$op->invoice_id)['ledger'] as $entry){
            $id=DisputeRecoveries::dispute($entry);
            if($id!==null&&hash_equals($key,DisputeRecoveries::key('account_money_hold',$id))){
                return ['dispute_id'=>$id,'lost_minor'=>(int)$entry['amount_minor'],'owed_minor'=>0,'removed_minor'=>0,'remainder_minor'=>0];
            }
        }
        return null;
    }
    public static function release(User $actor,int $alertId): void
    {
        OperationClaims::outside();$alert=DB::table('domains_obligation_alerts')->find($alertId);
        $seed=Domain::findOrFail($alert?->domain_id);
        DB::transaction(function()use($actor,$alertId,$seed):void {
            $l=HoldingLocks::take($seed,(int)$actor->id,released:$seed->reservation_name===null);
            StaffOperationReview::requirePermission($l['staff'],'release',null);
            $alert=DB::table('domains_obligation_alerts')->where('id',$alertId)->where('domain_id',$seed->id)->where('code','account_money_hold')->lockForUpdate()->first();
            if(!$alert||$alert->resolved_at!==null){throw new \DomainException('Hold already released.');}
            $op=Operation::findOrFail($alert->operation_id);
            $evidence=self::evidence($op,$alert->alert_key)??throw new \DomainException('Dispute evidence needs a check.');
            $dispute=$evidence['dispute_id'];
            DB::table('domains_obligation_alerts')->where('id',$alertId)->update(['resolved_at'=>now()->timestamp]);
            Audit::create(['user_type'=>$actor->getMorphClass(),'user_id'=>$actor->id,'event'=>'account_money_hold_released',
                'auditable_type'=>(new Invoice)->getMorphClass(),'auditable_id'=>$op->invoice_id,'old_values'=>[],
                'new_values'=>['dispute_id'=>$dispute],'url'=>url('/admin/invoices/'.$op->invoice_id)]);
        });
    }
}
