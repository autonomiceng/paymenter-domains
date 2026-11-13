<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Server,User};
use Illuminate\Support\Facades\{Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Each cursor claim commits before row work. A crash skips only until the next pass; each move is atomic. */
final class HistoryRetention
{
    public const TABLES=['test_sends'=>'domains_enom_test_sends','deliveries'=>'domains_watch_deliveries','notices'=>'domains_watch_notices',
        'registry'=>'domains_watch_registry','watch_dns'=>'domains_watch_observations','managed_dns'=>'domains_managed_public_observations',
        'watch_audit'=>'domains_watch_audit','audit_events'=>'domains_audit_archives','reminders'=>'domains_reminder_deliveries'];
    public static function cutoff(int $now,int $months): int
    {return \Carbon\CarbonImmutable::createFromTimestampUTC($now)->subMonthsNoOverflow($months)->getTimestamp();}
    public static function replayed(string $family,string $key): bool
    {return DB::table(RetentionSchema::KEYS)->where('family',$family)->where('replay_key',$key)->exists();}
    public static function remember(string $family,string $key): void
    {DB::table(RetentionSchema::KEYS)->insertOrIgnore(['family'=>$family,'replay_key'=>$key]);}
    public static function recorded(string $family,int $id,int $at): void
    {DB::table(RetentionSchema::MARKS)->insert(['family'=>$family,'source_id'=>$id,'recorded_at'=>$at]);}
    public static function tick(int $now): void
    {
        OperationClaims::outside();
        foreach(array_keys(self::TABLES) as $family){self::sweep($family,$now);}
        foreach(DB::table(RetentionSchema::COLD)->where('delete_after','<',$now)->orderBy('delete_after')->orderBy('id')->limit(100)->pluck('id') as $id){
            DB::table(RetentionSchema::COLD)->where('id',$id)->where('delete_after','<',$now)->delete();
        }
    }
    public static function sweep(string $family,int $now,int $limit=100): void
    {
        OperationClaims::outside();
        if(!isset(self::TABLES[$family])||$now<0||$limit<1||$limit>100){throw new \InvalidArgumentException('Invalid retention sweep.');}
        $table=self::TABLES[$family];
        $rows=self::claim($family,DB::table($table),$limit);
        foreach($rows as $hint){DB::transaction(static function()use($family,$table,$hint,$now):void {
            // No lock on progress survives into owner/account locks. Never lock two owners in one row transaction.
            if($family==='test_sends'){
                if(!DB::table(EnomTestSendLimitSchema::ACCOUNTS)->where('account',$hint->account)->lockForUpdate()->exists()){self::issue($family,$hint->id,'missing_account');return;}
            }elseif($family==='audit_events'){
                if(!Server::whereKey($hint->server_id)->lockForUpdate()->exists()){self::issue($family,$hint->id,'missing_server');return;}
            }else{
                $owner=$hint->user_id??($family==='reminders'?Domain::find($hint->domain_id)?->user_id:null);
                if($family==='reminders'&&$owner===null){self::issue($family,$hint->id,'missing_domain');return;}
                if($owner!==null&&!User::whereKey($owner)->lockForUpdate()->exists()){self::issue($family,$hint->id,'missing_owner');return;}
                if($family==='watch_audit'&&$hint->subject_type==='watch'){DB::table('domains_watch_names')->where('id',$hint->subject_id)->lockForUpdate()->first();}
                if($family==='reminders'){
                    $domain=Domain::whereKey($hint->domain_id)->lockForUpdate()->first();
                    if(!$domain||(int)$domain->user_id!==(int)$owner){self::issue($family,$hint->id,'changed_owner');return;}
                }
                if($family==='deliveries'){DB::table('domains_watch_notices')->where('id',$hint->notice_id)->lockForUpdate()->first();}
            }
            $query=DB::table($table)->where('id',$hint->id);$row=(clone $query)->lockForUpdate()->first();if(!$row){return;}
            // Current owner changes are not classified from an earlier hint.
            foreach(['user_id','account','server_id','domain_id','notice_id'] as $key){if(isset($hint->$key)&&($row->$key??null)!==$hint->$key){return;}}
            $at=RetentionFacts::at($family,$row);
            if(is_string($at)){self::issue($family,$row->id,$at);return;}
            if($at===null){return;} // Current authority or unresolved work.
            DB::table(RetentionSchema::MARKS)->where('family',$family)->where('source_id',$row->id)->update(['reason'=>null]);
            if($family==='test_sends'){
                if($at<$now-30*86400){$query->delete();}return;
            }
            if($at>=self::cutoff($now,13)){return;}
            $extra=[];
            if($family==='notices'){
                self::remember('notice',$row->notice_key);
                if(!DB::table('domains_watch_notices')->where('family',$row->family)->where('observation_id',$row->observation_id)->where('id','<>',$row->id)->exists()){
                    DB::table('domains_public_evidence_refs')->where('family',$row->family)->where('observation_id',$row->observation_id)->delete();
                }
            }
            if($family==='deliveries'){
                self::remember('delivery',$row->delivery_key);
                $extra=DB::table('domains_watch_mail_days')->where('delivery_id',$row->id)->get()->all();
                foreach($extra as $day){self::remember('mail_day',$day->scope);}
            }
            if($family==='reminders'){self::remember('reminder',$row->delivery_key);}
            if($family==='audit_events'){
                self::remember('audit_event',$row->event_key);
                self::remember('audit_event_data',AuditEvents::retentionDigest($row->event_key,(int)$row->server_id,$row->family,json_decode(Crypt::decryptString($row->payload),true,32,JSON_THROW_ON_ERROR)));
            }
            DB::table(RetentionSchema::COLD)->insert(['family'=>$family,'source_id'=>$row->id,'user_id'=>$row->user_id??null,'occurred_at'=>$at,
                'delete_after'=>\Carbon\CarbonImmutable::createFromTimestampUTC($at)->addYearsNoOverflow(3)->getTimestamp(),
                'payload'=>Crypt::encryptString(json_encode(['row'=>$row,'mail_days'=>$extra],JSON_THROW_ON_ERROR))]);
            if($family==='deliveries'){DB::table('domains_watch_mail_days')->where('delivery_id',$row->id)->delete();}
            if(in_array($family,['registry','watch_dns','managed_dns'],true)){
                DB::table('domains_public_evidence_refs')->where('family',$family)->where('observation_id',$row->id)->delete();
            }
            $query->delete();DB::table(RetentionSchema::MARKS)->where('family',$family)->where('source_id',$row->id)->delete();
        });}
    }
    public static function claim(string $family,\Illuminate\Database\Query\Builder $query,int $limit=100): \Illuminate\Support\Collection
    {
        return DB::transaction(static function()use($family,$query,$limit){
            $scan=DB::table(RetentionSchema::SCANS)->where('family',$family);
            // An upsert takes an exclusive row lock, including first use; no shared-lock upgrade or missing-key gap lock.
            DB::table(RetentionSchema::SCANS)->upsert([['family'=>$family]],['family'],['family']);
            $cursor=(clone $scan)->lockForUpdate()->sole()->cursor;
            $rows=(clone $query)->where('id','>',$cursor)->orderBy('id')->limit($limit)->get();
            $scan->update(['cursor'=>$rows->last()?->id??0]);return $rows;
        });
    }
    public static function issue(string $family,int $id,string $reason): void
    {
        DB::table(RetentionSchema::MARKS)->upsert([['family'=>$family,'source_id'=>$id,'reason'=>$reason]],['family','source_id'],['reason']);
    }
}
