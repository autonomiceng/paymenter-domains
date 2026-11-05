<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** A price increase never expands an issued account grant without an audited staff approval. */
final class ComplimentaryCosts
{
    public static function cap(Operation $op,string $usual):string
    {
        $approved=DB::table('domains_complimentary_approvals')->where('operation_id',$op->id)->orderByDesc('id')->value('amount');
        if($approved===null||WriteMoney::compare($approved,$usual)<=0){return $usual;}
        [$whole,$fraction]=explode('.',$approved);return $whole.'.'.(rtrim($fraction,'0')?:'0');
    }
    public static function require(Operation $op,Money $cost,string $usual):void
    {
        if(!isset($op->policy['account_complimentary'])||WriteMoney::compare($cost->amount,self::cap($op,$usual))<=0){return;}
        $save=static function()use($op,$cost,$usual):void {
            $row=Operation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            $row->update(['policy'=>[...$row->policy,'complimentary_cost_review'=>['amount'=>$cost->amount,'usual'=>$usual]]]);
        };
        if(DB::transactionLevel()===0){DB::transaction($save);}else{$save();}
        throw new WriteHeld('registrar_price_increased',$cost);
    }
    /** Exact unsent acquisition claim retained by a completed cost approval, including NameSilo. */
    public static function readmitted(Operation $op):bool
    {
        $a=$op->attempt??[];$f=$op->funding??[];
        if(!isset($op->policy['account_complimentary'])||$op->state!=='queued'||$op->safe_code!==null||!ExecutionWindow::priceReleased($op)
            ||($a['seal']??null)!=='absent'||($a['send_state']??null)!=='definitely_unsent'||!isset($a['claim_key'],$f['claim'])
            ||array_diff(array_keys($a),['claim_key','seal','send_state','capacity_deferrals','test_call_refusals'])||array_diff(array_keys($f),['claim'])
            ||isset($op->cost['actual'])||$op->provider_order_id!==null||$op->confirmed_expires_at!==null||$op->completed_at!==null||$op->lease_expires_at!==null){return false;}
        return DB::table('domains_complimentary_approvals')->where('operation_id',$op->id)->exists();
    }
    public static function message(Operation $op):?string
    {
        $review=$op->policy['complimentary_cost_review']??null;
        return $op->state==='held'&&$review?'Needs approval: registrar cost $'.number_format((float)$review['amount'],2,'.','').' is above the usual $'.number_format((float)$review['usual'],2,'.',''):null;
    }
    public static function approve(int $id,User $actor,string $amount,string $reason,Clock $clock):void
    {
        OperationClaims::outside();$seed=Operation::findOrFail($id)->domain;$reason=trim($reason);
        DB::transaction(static function()use($seed,$id,$actor,$amount,$reason,$clock):void {
            $l=HoldingLocks::take($seed,(int)$actor->id);$op=$l['operations']->firstWhere('id',$id);
            $staff=$l['staff'];$review=$op?->policy['complimentary_cost_review']??null;
            if(!$staff?->hasPermission('admin.domains.fund')||!$staff->hasPermission('admin.domains.manage')||$reason===''||strlen($reason)>500
                ||!$review||$review['amount']!==$amount||!self::message($op)||!isset($op->policy['account_complimentary'])
                ||($op->attempt['seal']??'absent')!=='absent'||isset($op->attempt['execution'])||isset($op->cost['actual'])){throw new \DomainException('Current unsent registrar cost approval required.');}
            ComplimentaryAccounts::require($op->policy['account_complimentary'],(int)$l['domain']->user_id);
            DB::table('domains_complimentary_approvals')->insert(['operation_id'=>$id,'actor_id'=>$staff->id,'amount'=>$amount,'reason'=>$reason,'created_at'=>$clock->now()]);
            $policy=$op->policy;unset($policy['renewal_admission']);
            $policy['staff_actions']=[...($policy['staff_actions']??[]),['action'=>'release','phase'=>'completed','operation_key'=>$op->operation_key,
                'generation'=>$op->authority_generation,'actor_id'=>$staff->id,'reason'=>$reason,'at'=>$clock->now()->format(DATE_ATOM),
                'before'=>['state'=>'held','reason'=>'registrar_price_increased'],'after'=>['state'=>'queued','reason'=>null]]];
            $op->update(['state'=>'queued','safe_code'=>null,'lease_expires_at'=>null,'policy'=>$policy]);
        });
    }
}
