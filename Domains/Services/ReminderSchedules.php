<?php
// Offsets adapted from Enom 444f31c Services/Reminders.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Server,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
/** Explicit enrollment and audited optimistic edits; installation never overwrites customizations. */
final class ReminderSchedules
{
    public static function offsets(string $value):array
    {
        if(strlen($value)>200){throw new \InvalidArgumentException('Reminder schedule too long.');}$days=[];
        foreach(preg_split('/[\s,]+/',trim($value),-1,PREG_SPLIT_NO_EMPTY)?:[] as $part){
            if(!ctype_digit($part)||(int)$part>365){throw new \InvalidArgumentException('Use reminder days from 0 to 365.');}
            $days[]=(int)$part;
        }
        $days=array_values(array_unique($days));sort($days);return $days;
    }
    public static function enroll(int $serverId):void
    {
        OperationClaims::outside();
        DB::transaction(function()use($serverId):void {
            Server::whereKey($serverId)->lockForUpdate()->firstOrFail();$a=Account::where('server_id',$serverId)->lockForUpdate()->firstOrFail();
            $row=DB::table('domains_reminder_schedules')->where('server_id',$serverId)->lockForUpdate()->first();
            if($row){if($row->account_fingerprint!==$a->account_fingerprint){throw new \DomainException('Reminder account changed.');}return;}
            DB::table('domains_reminder_schedules')->insert(['server_id'=>$serverId,'account_fingerprint'=>$a->account_fingerprint,
                'enabled'=>false,'before_days'=>'[1,7,30,60]','after_days'=>'[3]','audit'=>encrypt([])]);
        });
    }
    public static function save(User $actor,int $serverId,int $revision,bool $enabled,string $before,string $after,string $reason):void
    {
        OperationClaims::outside();$b=self::offsets($before);$a=self::offsets($after);
        if(!preg_match('/^[a-z][a-z0-9_]{4,63}$/D',$reason)){throw new \DomainException('A safe audit reason is required.');}
        DB::transaction(function()use($actor,$serverId,$revision,$enabled,$b,$a,$reason):void {
            $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();$role=$u->role()->lockForUpdate()->first();$u->setRelation('role',$role);
            if(!$u->hasPermission(Staff::VIEW)||!$u->hasPermission(Staff::POLICY)){throw new \DomainException('Reminder policy permission required.');}
            $s=Server::whereKey($serverId)->lockForUpdate()->firstOrFail();$account=Account::where('server_id',$serverId)->lockForUpdate()->firstOrFail();
            $q=DB::table('domains_reminder_schedules')->where('server_id',$serverId);$old=(clone $q)->lockForUpdate()->first();
            if(!$old||$s->extension!=='Domains'||$old->account_fingerprint!==$account->account_fingerprint||(int)$old->revision!==$revision){throw new \DomainException('Reload the reminder schedule.');}
            $audit=decrypt($old->audit);if(!is_array($audit)||count($audit)>=100){throw new \DomainException('Reminder audit limit reached.');}
            $next=['enabled'=>$enabled,'before_days'=>json_encode($b),'after_days'=>json_encode($a)];
            $audit[]=['actor_id'=>(int)$u->id,'reason'=>$reason,'at'=>gmdate(DATE_ATOM),'revision'=>$revision+1,
                'before'=>['enabled'=>(bool)$old->enabled,'before_days'=>$old->before_days,'after_days'=>$old->after_days],'after'=>$next];
            $q->update([...$next,'revision'=>$revision+1,'audit'=>encrypt($audit)]);
        });
    }
    /** Account turns and cursors commit before any mail work. Failure cannot pin the next account. */
    public static function next(int $now,int $limit=25):array
    {
        OperationClaims::outside();
        if($limit<1||$limit>25){throw new \InvalidArgumentException('Invalid reminder batch.');}
        return DB::transaction(function()use($now,$limit):array {
            $q=DB::table('domains_reminder_schedules');$rows=(clone $q)->where('due_at','<=',$now)->orderBy('turn')->orderBy('server_id')->limit(1)->lockForUpdate()->get();
            $turn=(int)$q->max('turn');$result=[];
            foreach($rows as $row){
                $ids=\Paymenter\Extensions\Servers\Domains\Models\Domain::where('server_id',$row->server_id)
                    ->where('id','>',$row->cursor)->orderBy('id')->limit($limit)->pluck('id');
                $result[]=$ids->all();(clone $q)->where('id',$row->id)->update(['turn'=>++$turn,'cursor'=>$ids->count()===$limit?$ids->last():0,'due_at'=>$now+300]);
            }
            return array_merge([],...$result);
        });
    }
}
