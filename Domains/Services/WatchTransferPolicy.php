<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Pure comparison rules. Public expiry alone never establishes transfer eligibility. */
final class WatchTransferPolicy
{
    public static function window(?string $expiry,int $now): bool
    {
        if (!$expiry || !preg_match('/^\d{4}-\d\d-\d\d$/D',$expiry)) { return false; }
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$expiry,new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d')!==$expiry) { return false; }
        $days=(int)floor(($date->getTimestamp()-$now)/86400);
        return $days>=14 && $days<=90;
    }
    public static function eligible(array $transfer,int $now): bool
    {
        return ($transfer['comparison_allowed']??false)===true && ($transfer['rdap_unchecked']??true)===false
            && self::window($transfer['base_expiry']??null,$now);
    }
    /** Only trusted, independently verified annual renewal evidence may enter this function. */
    public static function saving(?array $verified,string $transferMinor,string $name,int $now): ?string
    {
        if (!$verified || ($verified['verified']??false)!==true || ($verified['name']??null)!==$name
            || ($verified['currency']??null)!=='USD' || ($verified['tax_basis']??null)!=='exclusive'
            || ($verified['term']??null)!==1 || !is_int($verified['observed']??null) || !is_int($verified['expires']??null)
            || $verified['observed']>$now || $verified['expires']<=$now
            || !is_string($verified['minor']??null) || !preg_match('/^[0-9]{1,9}$/D',$verified['minor'])
            || !preg_match('/^[0-9]{1,9}$/D',$transferMinor)) { return null; }
        $difference=(int)$verified['minor']-(int)$transferMinor;
        return $difference>0?Storefront::money(intdiv($difference,100).'.'.str_pad((string)($difference%100),2,'0',STR_PAD_LEFT)):null;
    }
}
