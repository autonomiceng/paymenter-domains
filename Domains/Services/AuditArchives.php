<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Retained encrypted batches outside native settings, which upstream loads in full. */
final class AuditArchives
{
    public const TABLE='domains_audit_archives';
    /** Compatibility for callers supplying a batch; persistence is always one encrypted row per event. */
    public static function append(int $server,string $family,string $json): void
    {
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
