<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Twenty fast observations, then paced reads forever. No dispatch authority. */
final class ManagementReadRecovery
{
    public static function exhausted(array $a): bool
    { return ($a['management_observation_attempts']??$a['management_legacy_observation_attempts']??0)>=20||count($a['management_reads']??[])>=20; }
    public static function last(array $a): ?\DateTimeImmutable
    {
        $reads=$a['management_reads']??[];$read=$reads?end($reads):[];
        $at=$a['management_read_at']??$read['at']??$a['dispatch_attempted_at']??null;
        return is_string($at)?new \DateTimeImmutable($at):null;
    }
    public static function reserve(Operation $op,\DateTimeImmutable $now,bool $reviewed): bool
    {
        $last=self::last($op->attempt);$next=$last?->modify($reviewed?'+15 minutes':'+6 hours');
        if($next&&$now<$next){
            if($reviewed){throw new StaffReviewRefused('A status check was requested recently. Try again at '.$next->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i').' UTC. Automatic checks continue.');}
            return false;
        }
        $at=$now->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        $op->update(['attempt'=>[...$op->attempt,'management_read_at'=>$at,'management_recovery'=>[
            'number'=>($op->attempt['management_recovery']['number']??0)+1,'at'=>$at,'reviewed'=>$reviewed]]]);
        return true;
    }
    public static function reads(Operation $op): array
    { return [...($op->attempt['management_reads']??[]),...(isset($op->attempt['management_recovery_read'])?[$op->attempt['management_recovery_read']]:[])]; }
    public static function latest(Operation $op): array
    { $reads=self::reads($op);return $reads?end($reads):[]; }
    public static function guard(Operation $op): void
    {
        $old=$op->getOriginal('attempt')??[];$new=$op->attempt??[];
        $before=$old['management_read_at']??null;$after=$new['management_read_at']??null;
        if($after!==$before&&(!self::timestamp($after)||$before!==null&&$after<$before||($new['seal']??null)!=='sealed')){throw new \DomainException('Invalid management read time.');}
        $prior=$old['management_recovery']??null;$current=$new['management_recovery']??null;
        if($prior!==$current){
            $next=self::last($old)?->modify(($current['reviewed']??false)?'+15 minutes':'+6 hours');
            if(!is_array($current)||array_keys($current)!==['number','at','reviewed']||!is_int($current['number'])
                ||$current['number']!==($prior['number']??0)+1||!self::timestamp($current['at'])||!is_bool($current['reviewed'])
                ||($old['seal']??null)!=='sealed'||!self::exhausted($old)||$after!==$current['at']||$next&&new \DateTimeImmutable($current['at'])<$next){
                throw new \DomainException('Invalid management recovery reservation.');
            }
            if($current['reviewed']){
                $history=$op->policy['staff_actions']??[];$review=$history?end($history):[];
                if(($review['action']??null)!=='recheck'||($review['phase']??null)!=='read_requested'||($review['operation_key']??null)!==$op->operation_key||($review['generation']??null)!==$op->authority_generation){throw new \DomainException('Reviewed management recovery required.');}
            }
        }
        $read=$new['management_recovery_read']??null;
        if(($old['management_recovery_read']??null)!==$read&&(!is_array($read)||!$current||($read['recovery_number']??null)!==$current['number']
            ||($read['operation_key']??null)!==$op->operation_key||($read['generation']??null)!==$op->authority_generation||($read['action']??null)!==$op->action
            ||($old['management_recovery_read']['recovery_number']??0)>=$current['number'])){throw new \DomainException('Invalid management recovery read.');}
    }
    private static function timestamp(mixed $at): bool
    { return is_string($at)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D',$at)===1; }
}
