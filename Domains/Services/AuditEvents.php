<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Server,Setting};
use Illuminate\Support\Facades\{Crypt,DB};
/** Encrypted events, with legacy batch identities retained until checked retirement. */
final class AuditEvents
{
    public const FAMILIES=['staff_settings','catalogue_sync','reminders'];
    public const SETTINGS=['domains_staff_settings_audit'=>'staff_settings','domains_staff_settings_audit.catalogue'=>'catalogue_sync'];
    public static function append(int $server,string $family,array $entry,?string $key=null): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer audit transaction required.');}
        if(!in_array($family,self::FAMILIES,true)){throw new \LogicException('Unknown audit family.');}
        $json=json_encode($entry,JSON_THROW_ON_ERROR);$payload=Crypt::encryptString($json);
        if(strlen($payload)>64000){throw new \DomainException('Audit entry too large. Changes held.');}
        $key??=bin2hex(random_bytes(32));
        $old=DB::table(AuditArchives::TABLE)->where('event_key',$key)->first();
        if($old){
            if((int)$old->server_id!==$server||$old->family!==$family||self::decode($old->payload)!==$entry){throw new \DomainException('Audit event identity changed.');}
            return;
        }
        DB::table(AuditArchives::TABLE)->insert(['server_id'=>$server,'family'=>$family,'event_key'=>$key,'payload'=>$payload]);
    }
    private static function decode(string $payload): array
    {
        try{$row=json_decode(Crypt::decryptString($payload),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($row)){throw new \DomainException;}return $row;
        }catch(\Throwable){throw new \DomainException('Audit unavailable. Changes held.');}
    }
    public static function recent(int $server,string $family,int $limit=50,bool $lock=false): array
    {
        if(!in_array($family,self::FAMILIES,true)||$limit<1||$limit>100){throw new \InvalidArgumentException('Invalid audit window.');}
        $q=DB::table(AuditArchives::TABLE)->where('server_id',$server)->where('family',$family)->whereNotNull('event_key')->orderByDesc('id')->limit($limit);
        if($lock){$q->lockForUpdate();}
        return array_reverse($q->get()->map(static fn($r)=>self::decode($r->payload))->all());
    }
    /** Drain mutable batches under the existing server mutex before assigning archive-id/ordinal identities.
     * Only the reader cutover runs this, so readers never see a drained batch. Reruns import each ordinal once. */
    public static function import(): void
    {
        OperationClaims::outside();
        $servers=DB::table(AuditArchives::TABLE)->whereNull('event_key')->pluck('server_id');
        $servers=$servers->merge(DB::table('settings')->where('settingable_type',(new Server)->getMorphClass())->whereIn('key',array_keys(self::SETTINGS))->pluck('settingable_id'));
        $servers=$servers->merge(DB::table('domains_reminder_schedules')->pluck('server_id'))->unique()->sort();
        foreach($servers as $server){DB::transaction(static function()use($server):void {
            Server::whereKey($server)->lockForUpdate()->first();
            foreach(self::SETTINGS as $key=>$family){
                $settings=Setting::where('settingable_type',(new Server)->getMorphClass())->where('settingable_id',$server)->where('key',$key)->orderBy('id')->lockForUpdate()->get();
                foreach($settings as $setting){
                    if(!$setting->encrypted){throw new \DomainException('Audit unavailable. Changes held.');}
                    $rows=self::batch($setting->value,$family);
                    if($rows){AuditArchives::append((int)$server,$family,json_encode($rows,JSON_THROW_ON_ERROR));}
                    $setting->value='[]';$setting->save();
                }
            }
            $q=DB::table('domains_reminder_schedules')->where('server_id',$server);$schedule=(clone $q)->lockForUpdate()->first();
            if($schedule){
                try{$rows=decrypt($schedule->audit);}catch(\Throwable){throw new \DomainException('Reminder audit unavailable.');}
                if(!is_array($rows)||!array_is_list($rows)){throw new \DomainException('Reminder audit unavailable.');}
                if($rows){AuditArchives::append((int)$server,'reminders',json_encode(['rows'=>$rows,'through_revision'=>(int)$schedule->revision],JSON_THROW_ON_ERROR));}
                $q->update(['audit'=>encrypt([])]);
            }
            DB::table(AuditArchives::TABLE)->where('server_id',$server)->whereNull('event_key')->orderBy('id')->chunkById(25,static function($batches)use($server):void {
            foreach($batches as $batch){
                if(!in_array($batch->family,self::FAMILIES,true)){throw new \DomainException('Unknown retained audit family.');}
                try{$json=Crypt::decryptString($batch->payload);}catch(\Throwable){throw new \DomainException('Audit unavailable. Changes held.');}
                $rows=self::batch($json,$batch->family);
                foreach($rows as $ordinal=>$entry){self::append((int)$server,$batch->family,$entry,self::key((int)$batch->id,$ordinal));}
                // Every original ordinal must resolve to the exact retained event, including repeated payloads.
                foreach($rows as $ordinal=>$entry){
                    $copy=DB::table(AuditArchives::TABLE)->where('event_key',self::key((int)$batch->id,$ordinal))->sole();
                    if(self::decode($copy->payload)!==$entry){throw new \DomainException('Audit import count mismatch.');}
                }
            }
            });
        });}
    }
    private static function key(int $batch,int $ordinal): string {return hash('sha256','domains-audit:'.$batch.':'.$ordinal);}
    private static function batch(string $json,string $family): array
    {
        try{$rows=json_decode($json,true,32,JSON_THROW_ON_ERROR);
            if($family==='reminders'){$rows=$rows['rows']??null;}
            if(!is_array($rows)||!array_is_list($rows)){throw new \DomainException;}
            foreach($rows as $r){if(!is_array($r)){throw new \DomainException;}}
            return $rows;
        }catch(\Throwable){throw new \DomainException('Audit unavailable. Changes held.');}
    }
}
