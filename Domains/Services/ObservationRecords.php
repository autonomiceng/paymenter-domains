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
    public static function save(Domain $d, array $families, \DateTimeImmutable $now, ?array $capabilities=null): void
    {
        $p=$d->observation??[]; $clean=[];
        foreach (['dns','srv','ds'] as $kind) {
            $clean[$kind]=RecordInventory::family($kind,$families[$kind]??[],$d->registrar_key,$d->domain);
        }
        if (count($families['glue']['records']??[])>GlueDiscoveryLimit::HOSTS) { throw new GlueDiscoveryLimit(); }
        $glue=[];
        foreach (array_slice($families['glue']['records']??[],0,GlueDiscoveryLimit::HOSTS,true) as $host=>$ip) {
            try { $v=ManagementPayload::normalize('glue_add',['host'=>$host,'ip'=>$ip],$d->domain,$d->registrar_key); $glue[$v['host']]=$v['ip']; }
            catch (\Throwable) { throw new \DomainException('Invalid child-host read.'); }
        }
        $glueState=$families['glue']['state']??null;
        $clean['glue']=['state'=>in_array($glueState,['complete','partial'],true)?$glueState:'unknown','records'=>$glue];
        $clean['glue']['candidates']=array_keys(GlueRecords::hosts($d->domain,[],$families['glue']['candidates']??[]));
        $record=['identity'=>self::identity($d),'at'=>$now->format(DATE_ATOM),'sequence'=>$p['management_sequence']??0,'families'=>$clean];
        if ($families['glue']['read_failed']??false) { $record['families']['glue']['read_failed']=true; }
        if ($capabilities!==null) {
            $record+=['format'=>1,'capability_revision'=>$capabilities['revision']??null,'expected_families'=>$capabilities['families']??null];
            $record=RecordFormat::read($record,$d->registrar_key)??throw new \DomainException('Observation capabilities unavailable.');
            $record['settings']=RecordInventory::settings($families['settings']??[]);
            $prior=self::current($d,$now);
            foreach (['families','settings'] as $group) {
                if (!isset($record[$group])) { continue; }
                foreach ($record[$group] as $kind=>&$family) {
                    // Historical last-good evidence is diagnostic only and never restores edit authority.
                    $old=$prior[$group][$kind]??[];
                    $family['at']=$record['at'];
                    $family['last_good']=($family['state']??null)==='complete'
                        ? ['at'=>$record['at'],...array_intersect_key($family,array_flip(['records','facts']))]
                        : ($old['last_good']??(($old['state']??null)==='complete'?['at'=>$prior['at'],...array_intersect_key($old,array_flip(['records','facts']))]:null));
                } unset($family);
            }
            $record['last_complete_at']=RecordInventory::incomplete($record)?($prior['last_complete_at']??null):$record['at'];
        }
        $complete=$capabilities!==null?!RecordInventory::incomplete($record):($d->registrar_key==='namesilo'?$clean['glue']['state']==='complete':
            !array_filter(['dns','srv','ds'],fn($kind)=>$clean[$kind]['state']!=='complete')&&in_array($families['glue']['state']??null,['partial','complete'],true));
        if ($complete&&!($families['glue']['read_failed']??false)&&!isset($families['failure_code'])) { unset($p['record_failure'],$p['record_hold'],$p['record_reads']); }
        $p['records']=$record;
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
    public static function hold(Domain $d, string $reason, \DateTimeImmutable $now, ?string $code=null, ?string $family=null): void
    {
        $p=$d->observation??[];
        $p['record_hold']=['identity'=>self::identity($d),'reason'=>$reason,'at'=>$now->format(DATE_ATOM)];
        if ($code!==null) { $p['record_hold']['code']=$code; }
        if (in_array($family,['dns','srv','ds','glue','contacts','nameservers','flags'],true)) { $p['record_hold']['family']=$family; }
        $d->update(['observation'=>$p]);
    }
    public static function incomplete(Domain $d, ?\DateTimeImmutable $now=null): bool
    { $r=self::current($d,$now);return $r===null||RecordInventory::incomplete($r); }
    public static function current(Domain $d, ?\DateTimeImmutable $now=null): ?array
    {
        $now??=app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now();
        $r=$d->observation['records']??null;
        if (!is_array($r)||($r['identity']??null)!==self::identity($d)||!is_string($r['at']??null)
            || !is_int($r['sequence']??null)||$r['sequence']<0||$r['sequence']>($d->observation['management_sequence']??0)) { return null; }
        try { $at=new \DateTimeImmutable($r['at']);if ($at->format(DATE_ATOM)!==$r['at']||($now&&$at>$now)) { return null; } }
        catch (\Throwable) { return null; }
        $r=RecordFormat::read($r,$d->registrar_key);
        if ($r && isset($r['format'])) {
            foreach (['dns','srv','ds'] as $kind) {
                $f=$r['families'][$kind]??[];
                $r['families'][$kind]=array_replace($f,RecordInventory::family($kind,$f,$d->registrar_key,$d->domain));
            }
            if (isset($r['settings'])) { $r['settings']=RecordInventory::settings($r['settings']); }
        }
        return $r;
    }
}
