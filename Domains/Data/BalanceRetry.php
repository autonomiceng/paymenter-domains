<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OperationTerminalProof,RefundProof};
/** Bounded system authority, separate from staff history. It never replaces original claim/funding evidence. */
final readonly class BalanceRetry
{
    private function __construct(public array $record, public ?Money $cost, public ?\DateTimeImmutable $window) {}
    public static function from(Operation $op): ?self
    {
        if(!array_key_exists('balance_retry',$op->policy??[])){return null;}
        $r=$op->policy['balance_retry'];
        if(!is_array($r)||array_keys($r)!==['type','operation_key','generation','claim_key','request_fingerprint','held_at','tries','window_at','cost','cap_refusal']
            ||$r['type']!=='registrar_balance'||$r['operation_key']!==$op->operation_key||$r['generation']!==$op->authority_generation
            ||!in_array($op->action,['register','transfer'],true)
            ||$r['claim_key']!==($op->attempt['claim_key']??null)||$r['claim_key']!==null&&(!is_string($r['claim_key'])||!preg_match('/^[a-f0-9]{32}$/D',$r['claim_key']))
            ||$r['request_fingerprint']!==$op->request_fingerprint||$r['request_fingerprint']!==null&&(!is_string($r['request_fingerprint'])||!preg_match('/^[a-f0-9]{64}$/D',$r['request_fingerprint']))
            ||!is_int($r['tries'])||$r['tries']<0||$r['tries']>1000000){throw new \DomainException('Invalid balance retry authority.');}
        // Stored pre-claim checks acquire identity only through the normal first claim.
        if($r['claim_key']===null?($op->started_at!==null||$op->request_fingerprint!==null||($op->attempt??[])!==[]&&!self::unclaimedTerminal($op)
            ||array_intersect(['claim','seal'],array_keys($op->funding??[]))):(!$op->started_at||$r['request_fingerprint']===null)){
            throw new \DomainException('Invalid balance retry claim.');
        }
        $held=self::at($r['held_at']);$window=$r['window_at']===null?null:self::at($r['window_at']);
        if($window===null&&$op->started_at!==null&&$held<$op->started_at||($r['tries']===0)!==($window===null)||$window!==null&&$window<$held){throw new \DomainException('Invalid balance retry window.');}
        $cost=$r['cost'];
        if($cost!==null&&(!is_array($cost)||array_keys($cost)!==['amount','currency']||!is_string($cost['amount'])||$cost['currency']!=='USD')){
            throw new \DomainException('Invalid balance retry cost.');
        }
        $cap=$r['cap_refusal'];
        if($cap!==null){
            if(!$op->started_at||!is_array($cap)||array_keys($cap)!==['dispatch_attempted_at','authorized_cost','refused_at']
                ||!is_array($cap['authorized_cost'])||array_keys($cap['authorized_cost'])!==['amount','currency']
                ||!is_string($cap['authorized_cost']['amount'])||$cap['authorized_cost']['currency']!=='USD'
                ||self::at($cap['dispatch_attempted_at'])< $op->started_at||self::at($cap['refused_at'])<self::at($cap['dispatch_attempted_at'])){
                throw new \DomainException('Invalid balance retry cap proof.');
            }
            new Money($cap['authorized_cost']['amount'],'USD');
        }
        return new self($r,$cost===null?null:new Money($cost['amount'],$cost['currency']),$window);
    }
    public static function held(Operation $op,\DateTimeImmutable $now,?Money $cost): self
    {
        $r=self::from($op)?->record??['type'=>'registrar_balance','operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,
            'claim_key'=>$op->attempt['claim_key']??null,'request_fingerprint'=>$op->request_fingerprint,'held_at'=>self::stamp($now),'tries'=>0,'window_at'=>null,'cost'=>null,'cap_refusal'=>null];
        if($cost){$r['cost']=['amount'=>$cost->amount,'currency'=>$cost->currency];}
        $copy=clone $op;$copy->policy=[...$op->policy,'balance_retry'=>$r];return self::from($copy);
    }
    public function claimed(Operation $op): self
    {
        if($this->record['claim_key']!==null){throw new \DomainException('Retry already has its original claim.');}
        $copy=clone $op;$copy->policy=[...$op->policy,'balance_retry'=>array_replace($this->record,
            ['claim_key'=>$op->attempt['claim_key'],'request_fingerprint'=>$op->request_fingerprint])];return self::from($copy);
    }
    public function admitted(Operation $op,\DateTimeImmutable $now): self
    {
        $copy=clone $op;$copy->policy=[...$op->policy,'balance_retry'=>array_replace($this->record,
            ['tries'=>min(1000000,$this->record['tries']+1),'window_at'=>self::stamp($now)])];return self::from($copy);
    }
    public function refused(Operation $op,\DateTimeImmutable $now): self
    {
        $copy=clone $op;$copy->policy=[...$op->policy,'balance_retry'=>array_replace($this->record,['cap_refusal'=>[
            'dispatch_attempted_at'=>$op->attempt['dispatch_attempted_at'],'authorized_cost'=>$op->attempt['authorized_cost'],'refused_at'=>self::stamp($now)]])];
        return self::from($copy);
    }
    public function delay(): int {return min(21600,900*(2**min(5,$this->record['tries'])));}
    /** Closing an unsent pre-claim retry retains its history, never creates purchase authority. */
    private static function unclaimedTerminal(Operation $op): bool
    {
        if(($op->attempt['terminal']??null)!==['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation]
            ||array_diff(array_keys($op->attempt??[]),['seal','send_state','disposition','rejection_basis','terminal'])){return false;}
        try {RefundProof::requireUnsent($op,$op->domain);return OperationTerminalProof::safe($op,$op->domain);}
        catch(\Throwable){return false;}
    }
    private static function stamp(\DateTimeImmutable $at): string {return $at->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);}
    private static function at(mixed $at): \DateTimeImmutable
    {
        if(!is_string($at)){throw new \DomainException('Invalid balance retry time.');}
        try{$time=new \DateTimeImmutable($at);if(self::stamp($time)!==$at){throw new \DomainException;}}
        catch(\Throwable){throw new \DomainException('Invalid balance retry time.');}return $time;
    }
}
