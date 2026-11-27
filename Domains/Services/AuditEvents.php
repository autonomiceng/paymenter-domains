<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{Crypt,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Holding\Retention\HistoryRetention;
/** Encrypted staff and reminder history. */
final class AuditEvents
{
    public const FAMILIES=['staff_settings','catalogue_sync','reminders'];
    public static function append(int $server,string $family,array $entry,?string $key=null): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer audit transaction required.');}
        if(!in_array($family,self::FAMILIES,true)){throw new \LogicException('Unknown audit family.');}
        $json=json_encode($entry,JSON_THROW_ON_ERROR);$payload=Crypt::encryptString($json);
        if(strlen($payload)>64000){throw new \DomainException('Audit entry too large. Changes held.');}
        $key??=bin2hex(random_bytes(32));
        if(Schema::hasTable(RetentionSchema::KEYS)&&HistoryRetention::replayed('audit_event',$key)){
            if(!HistoryRetention::replayed('audit_event_data',self::retentionDigest($key,$server,$family,$entry))){throw new \DomainException('Audit event identity changed.');}
            return;
        }
        $old=DB::table(AuditArchives::TABLE)->where('event_key',$key)->first();
        if($old){
            if((int)$old->server_id!==$server||$old->family!==$family||self::decode($old->payload)!==$entry){throw new \DomainException('Audit event identity changed.');}
            return;
        }
        DB::table(AuditArchives::TABLE)->insert(['server_id'=>$server,'family'=>$family,'event_key'=>$key,'payload'=>$payload]);
    }
    public static function retentionDigest(string $key,int $server,string $family,array $entry): string
    {return hash('sha256',json_encode([$key,$server,$family,$entry],JSON_THROW_ON_ERROR));}
    private static function decode(string $payload): array
    {
        try{$row=json_decode(Crypt::decryptString($payload),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($row)){throw new \DomainException;}return $row;
        }catch(\Throwable){throw new \DomainException('Audit unavailable. Changes held.');}
    }
    public static function recent(int $server,string $family,int $limit=50,bool $lock=false): array
    {return array_values(self::recentById($server,$family,$limit,$lock));}
    /** Oldest first, keyed by archive id, so windows merged from one table keep their write order. */
    public static function recentById(int $server,string $family,int $limit=50,bool $lock=false): array
    {
        if(!in_array($family,self::FAMILIES,true)||$limit<1||$limit>100){throw new \InvalidArgumentException('Invalid audit window.');}
        $q=DB::table(AuditArchives::TABLE)->where('server_id',$server)->where('family',$family)->whereNotNull('event_key')->orderByDesc('id')->limit($limit);
        if($lock){$q->lockForUpdate();}
        return array_reverse($q->get()->mapWithKeys(static fn($r)=>[(int)$r->id=>self::decode($r->payload)])->all(),true);
    }
}
