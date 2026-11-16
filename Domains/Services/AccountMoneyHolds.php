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
                foreach(InvoiceMoney::project((int)$op->invoice_id)['ledger'] as $e){
                    $id=DisputeRecoveries::dispute($e);if($id===null||!hash_equals($alert->alert_key,DisputeRecoveries::key('account_money_hold',$id))){continue;}
                    $owed=$removed=$remainder=0;
                    foreach(Operation::where('invoice_id',$op->invoice_id)->get() as $peer){foreach($peer->funding['dispute_holds']??[] as $h){
                        if($h['dispute_id']===$id){$owed+=$h['owed_minor'];$removed+=$h['removed_minor'];$remainder+=$h['remainder_minor'];}
                    }}
                    $details[]='Dispute '.$id.': lost $'.PricingRule::decimal((int)$e['amount_minor']).', owed $'.PricingRule::decimal($owed)
                        .', removed $'.PricingRule::decimal($removed).', remainder $'.PricingRule::decimal($remainder).'.';
                }
            }catch(\Throwable){$details[]='Dispute evidence needs a check on invoice #'.$op->invoice_id.'.';}
        }
        return implode(' ',$details);
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
            $op=Operation::findOrFail($alert->operation_id);$dispute=null;
            foreach(InvoiceMoney::project((int)$op->invoice_id)['ledger'] as $entry){
                $id=DisputeRecoveries::dispute($entry);
                if($id!==null&&hash_equals($alert->alert_key,DisputeRecoveries::key('account_money_hold',$id))){$dispute=$id;break;}
            }
            if($dispute===null){throw new \DomainException('Dispute evidence needs a check.');}
            DB::table('domains_obligation_alerts')->where('id',$alertId)->update(['resolved_at'=>now()->timestamp]);
            Audit::create(['user_type'=>$actor->getMorphClass(),'user_id'=>$actor->id,'event'=>'account_money_hold_released',
                'auditable_type'=>(new Invoice)->getMorphClass(),'auditable_id'=>$op->invoice_id,'old_values'=>[],
                'new_values'=>['dispute_id'=>$dispute],'url'=>url('/admin/invoices/'.$op->invoice_id)]);
        });
    }
}
