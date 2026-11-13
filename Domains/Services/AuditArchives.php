<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{Crypt,DB};
/** Retained encrypted batches outside native settings, which upstream loads in full. */
final class AuditArchives
{
    public const TABLE='domains_audit_archives';
    /** Caller holds the server/account mutex; archive and next current batch commit together. */
    public static function retainBatch(int $server,string $family,string $json): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer audit transaction required.');}
        if(!in_array($family,['staff_settings','catalogue_sync','reminders'],true)){throw new \LogicException('Unknown audit family.');}
        $payload=Crypt::encryptString($json);
        if(strlen($payload)>64000){throw new \DomainException('Audit archive too large. Changes held.');}
        DB::table(self::TABLE)->insert(['server_id'=>$server,'family'=>$family,'payload'=>$payload]);
    }
    /** Compatibility for callers supplying a batch; persistence is always one encrypted row per event. */
    public static function append(int $server,string $family,string $json): void
    {
        if(!\Illuminate\Support\Facades\Schema::hasColumn(self::TABLE,'event_key')){self::retainBatch($server,$family,$json);return;}
        $rows=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if($family==='reminders'){$rows=$rows['rows']??null;}
        if(!is_array($rows)||!array_is_list($rows)){throw new \DomainException('Audit unavailable. Changes held.');}
        foreach($rows as $entry){AuditEvents::append($server,$family,$entry);}
    }
    public static function latest(int $server,string $family,bool $lock=false): ?string
    {
        $rows=AuditEvents::recent($server,$family,100,$lock);
        return $rows?json_encode($rows,JSON_THROW_ON_ERROR):null;
    }
}
