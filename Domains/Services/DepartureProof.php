<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** The write-once departure record and its pure checks. No queries. */
final class DepartureProof
{
    public const FIELDS=['domain_id','name','user_id','service_id','server_id','registrar_key','account_fingerprint','authority_generation'];
    /** @return array<string,int|string> */
    public static function identity(Domain $d): array
    {
        return ['domain_id'=>(int)$d->id,'name'=>(string)$d->domain,'user_id'=>(int)$d->user_id,'service_id'=>(int)$d->service_id,
            'server_id'=>(int)$d->server_id,'registrar_key'=>(string)$d->registrar_key,'account_fingerprint'=>(string)$d->account_fingerprint,
            'authority_generation'=>(int)$d->authority_generation];
    }
    /** The frozen record matches every identity field of this row. Reservation is not checked here. */
    public static function matches(Domain $d): bool
    {
        try{$record=$d->renewal_policy['departure']??null;}catch(\Throwable){return false;}
        if(!is_array($record)||!is_array($record['identity']??null)||!in_array($record['reason']??null,array_keys(HoldingDeparture::REASONS),true)
            ||!is_string($record['confirmed_at']??null)||!is_int($record['actor_id']??null)||!is_array($record['invoices']??null)){return false;}
        return $record['identity']===self::identity($d);
    }
    /** Departed, released and frozen: the only proof later accounting and re-entry accept. */
    public static function verified(Domain $d): bool
    { return $d->status==='departed'&&$d->reservation_name===null&&self::matches($d); }
}
