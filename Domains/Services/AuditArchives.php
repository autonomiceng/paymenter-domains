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
    public static function append(int $server,string $family,string $json): void
    {
        if(DB::transactionLevel()!==1){throw new \LogicException('Outer audit transaction required.');}
        if(!in_array($family,['staff_settings','reminders'],true)){throw new \LogicException('Unknown audit family.');}
        $payload=Crypt::encryptString($json);
        if(strlen($payload)>64000){throw new \DomainException('Audit archive too large. Changes held.');}
        DB::table(self::TABLE)->insert(['server_id'=>$server,'family'=>$family,'payload'=>$payload]);
    }
    public static function latest(int $server,string $family,bool $lock=false): ?string
    {
        $q=DB::table(self::TABLE)->where('server_id',$server)->where('family',$family)->orderByDesc('id')->limit(1);
        if($lock){$q->lockForUpdate();}$row=$q->first();
        if(!$row){return null;}
        try{return Crypt::decryptString($row->payload);}catch(\Throwable){throw new \DomainException('Audit unavailable. Changes held.');}
    }
}
