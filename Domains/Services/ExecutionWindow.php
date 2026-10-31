<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\BalanceRetry;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Dispatch and reconciliation must resolve the same audited attempt window. */
final class ExecutionWindow
{
    public const BALANCE_HOLDS=[...BalanceRetries::INITIAL_CODES,...BalanceRetries::REVIEW_CODES,...WriteHeld::UNSENT_REFUSALS,'write_acceptance_required','processing','write_disabled',
        'write_rate_limited','nameservers_required','registry_details_unsupported','enom_test_setup_required','enom_test_transfer_setup_required'];
    /** A validated staff release or system admission grants a bounded new window without changing original claim evidence. */
    public static function start(Operation $op, ?string &$reason = null): ?\DateTimeImmutable
    {
        $start=$op->started_at;$reason=null;
        $system=BalanceRetry::from($op);$balance=$system!==null;
        foreach(self::releases($op) as [$at,$from]){
            if(in_array($from,BalanceRetries::RELEASE_CODES,true)){$balance=true;}
            if($at>$start&&(($op->registrar_key==='namesilo'&&$from==='namesilo_account_guard')
                ||($balance&&in_array($from,self::BALANCE_HOLDS,true)))){$start=$at;$reason=$from;}
        }
        if($system?->window&&($start===null||$system->window>$start)){$start=$system->window;$reason=($op->policy['read_retries']??0)>0?'registrar_read_failed':'registrar_balance_insufficient';}
        return $start;
    }
    /** Only identity-bound admission can carry a balance recovery through later local holds. */
    public static function balanceReleased(Operation $op): bool
    {
        foreach(self::releases($op) as [$at,$from]){if(in_array($from,BalanceRetries::RELEASE_CODES,true)||in_array($from,BalanceRetries::REVIEW_CODES,true)&&BalanceRetry::from($op)!==null){return true;}}
        return BalanceRetry::from($op)?->window!==null;
    }
    private static function releases(Operation $op): iterable
    {
        $history=$op->policy['staff_actions']??[];
        if(!$op->started_at||!in_array($op->action,['register','transfer'],true)||!is_array($history)||!array_is_list($history)){return;}
        foreach($history as $event){
            if(!is_array($event)||($event['action']??null)!=='release'||($event['phase']??null)!=='completed'
                ||($event['operation_key']??null)!==$op->operation_key||($event['generation']??null)!==$op->authority_generation
                ||!is_array($event['before']??null)||($event['before']['state']??null)!=='held'||!is_string($event['before']['reason']??null)
                ||!is_array($event['after']??null)||($event['after']['state']??null)!=='queued'
                ||!array_key_exists('reason',$event['after'])||$event['after']['reason']!==null||!is_string($event['at']??null)){continue;}
            try{$at=new \DateTimeImmutable($event['at']);if($at->format(DATE_ATOM)!==$event['at']){continue;}}
            catch(\Throwable){continue;}
            if($at>=$op->started_at){yield [$at,$event['before']['reason']];}
        }
    }
}
