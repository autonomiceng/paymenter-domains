<?php
// Enom 444f31c snapshot/glue refresh; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Encrypted inventory with explicit completeness, current identity and readback-sequence binding. */
final class ObservationRecords
{
    public static function identity(Domain $d): array
    {
        return ['domain_id'=>$d->id,'name'=>$d->domain,'registrar'=>$d->registrar_key,'server_id'=>$d->server_id,
            'fingerprint'=>$d->account_fingerprint,'generation'=>$d->authority_generation,'provider_id'=>$d->provider_domain_id];
    }
    public static function save(Domain $d, array $families, \DateTimeImmutable $now): void
    {
        $p=$d->observation??[]; $clean=[];
        foreach (['dns','srv','ds'] as $kind) {
            $f=$families[$kind]??[]; $rows=$f['records']??null; $clean[$kind]=['state'=>'unknown'];
            if (($f['state']??null)!=='complete'||!is_array($rows)||!array_is_list($rows)||count($rows)>50) { continue; }
            try {
                ManagementPayload::canonical($kind,$rows);
                $clean[$kind]=['state'=>'complete','records'=>$rows];
            } catch (\Throwable) {}
        }
        if (count($families['glue']['records']??[])>50) { throw new \DomainException('Child-host history limit reached.'); }
        $glue=[];
        foreach (array_slice($families['glue']['records']??[],0,50,true) as $host=>$ip) {
            try { $v=ManagementPayload::normalize('glue_add',['host'=>$host,'ip'=>$ip],$d->domain,$d->registrar_key); $glue[$v['host']]=$v['ip']; }
            catch (\Throwable) {}
        }
        $clean['glue']=['state'=>'partial','records'=>$glue];
        $p['records']=['identity'=>self::identity($d),'at'=>$now->format(DATE_ATOM),'sequence'=>$p['management_sequence']??0,'families'=>$clean];
        $d->update(['observation'=>$p]);
    }
    /** Staff recovery is needed for either a record hold or stopped current scheduled reads. */
    public static function stopped(Domain $d): bool
    { return self::held($d) || ObservationWork::stopped($d); }
    public static function held(Domain $d): bool
    { return ($d->observation['record_hold']['identity']??null)===self::identity($d); }
    public static function hold(Domain $d, string $reason, \DateTimeImmutable $now): void
    {
        $p=$d->observation??[];
        $p['record_hold']=['identity'=>self::identity($d),'reason'=>$reason,'at'=>$now->format(DATE_ATOM)];
        $d->update(['observation'=>$p]);
    }
    public static function current(Domain $d): ?array
    {
        $r=$d->observation['records']??null;
        return is_array($r)&&($r['identity']??null)===self::identity($d)?$r:null;
    }
}
