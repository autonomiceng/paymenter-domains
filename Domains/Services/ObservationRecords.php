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
        if (count($families['glue']['records']??[])>GlueDiscoveryLimit::HOSTS) { throw new GlueDiscoveryLimit(); }
        $glue=[];
        foreach (array_slice($families['glue']['records']??[],0,GlueDiscoveryLimit::HOSTS,true) as $host=>$ip) {
            try { $v=ManagementPayload::normalize('glue_add',['host'=>$host,'ip'=>$ip],$d->domain,$d->registrar_key); $glue[$v['host']]=$v['ip']; }
            catch (\Throwable) { throw new \DomainException('Invalid child-host read.'); }
        }
        $clean['glue']=['state'=>($families['glue']['state']??null)==='complete'?'complete':'partial','records'=>$glue];
        $clean['glue']['candidates']=array_keys(GlueRecords::hosts($d->domain,[],$families['glue']['candidates']??[]));
        $complete=$d->registrar_key==='namesilo'?$clean['glue']['state']==='complete':
            !array_filter(['dns','srv','ds'],fn($kind)=>$clean[$kind]['state']!=='complete')&&in_array($families['glue']['state']??null,['partial','complete'],true);
        if ($complete&&!($families['glue']['read_failed']??false)) { unset($p['record_failure'],$p['record_hold'],$p['record_reads']); }
        $p['records']=['identity'=>self::identity($d),'at'=>$now->format(DATE_ATOM),'sequence'=>$p['management_sequence']??0,'families'=>$clean];
        $d->update(['observation'=>$p]);
    }
    /** Failure is separate from saved inventory and survives staff resume until new record evidence. */
    public static function failPrimary(Domain $d, \DateTimeImmutable $now, string $code='read_failed'): void
    {
        $p=$d->observation??[];
        $p['record_failure']=['identity'=>self::identity($d),'at'=>$now->format(DATE_ATOM),'code'=>$code];
        if (!self::held($d)) { $p['record_hold']=[...$p['record_failure'],'reason'=>$code]; }
        $d->update(['observation'=>$p]);
    }
    /** Staff recovery is needed for either a record hold or stopped current scheduled reads. */
    public static function stopped(Domain $d): bool
    { return self::held($d) || ObservationWork::stopped($d); }
    public static function held(Domain $d): bool
    { return ($d->observation['record_hold']['identity']??null)===self::identity($d); }
    /** Shared by the staff inbox, sync notice and Missing DNS row. */
    public static function limitNotice(Domain $d): ?string
    {
        if (!self::held($d)) { return null; }
        return match($d->observation['record_hold']['reason']??null) {
            'child_host_limit'=>"Missing: more than ".GlueDiscoveryLimit::HOSTS." child hosts to check. Ask your site admin to remove unused entries from this domain's saved discovery data, registrar nameservers and DNS templates so at most ".GlueDiscoveryLimit::HOSTS." child hosts remain, then use Sync now.",
            'history_limit'=>'Missing: saved record history needs a fresh read. Use Sync now to refresh DNS records.',
            default=>null,
        };
    }
    public static function hold(Domain $d, string $reason, \DateTimeImmutable $now, ?string $code=null): void
    {
        $p=$d->observation??[];
        $p['record_hold']=['identity'=>self::identity($d),'reason'=>$reason,'at'=>$now->format(DATE_ATOM)];
        if ($code!==null) { $p['record_hold']['code']=$code; }
        $d->update(['observation'=>$p]);
    }
    public static function current(Domain $d): ?array
    {
        $r=$d->observation['records']??null;
        return is_array($r)&&($r['identity']??null)===self::identity($d)?RecordFormat::read($r,$d->registrar_key):null;
    }
}
