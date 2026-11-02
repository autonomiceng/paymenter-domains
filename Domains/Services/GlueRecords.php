<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,WatchProfile};
/** Glue discovery candidates and saved read evidence. Never writes registrar state or templates. */
final class GlueRecords
{
    public static function hosts(string $name, array $known, array $hosts): array
    {
        foreach ($hosts as $host) {
            if (!is_string($host)) { continue; }
            $host=strtolower(rtrim($host,'.'));
            if (str_ends_with($host,'.'.$name) && filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) { $known[$host]??=''; }
        }
        if (count($known)>GlueDiscoveryLimit::HOSTS) { throw new GlueDiscoveryLimit(); }
        return $known;
    }
    public static function known(Domain $d, array $known, array $nameservers=[]): array
    {
        $candidates=ObservationRecords::current($d)['families']['glue']['candidates']??[];
        $known=array_replace(self::hosts($d->domain,[],$candidates),$known);
        $known=self::hosts($d->domain,$known,[...$nameservers,...(ObservationProjection::nameservers($d)?->nameservers??[])]);
        if (Schema::hasTable('domains_watch_profiles')) {
            foreach (WatchProfile::orderBy('id')->cursor() as $template) { $known=self::hosts($d->domain,$known,$template->nameservers??[]); }
        }
        return $known;
    }
    /** Called only after the engine revalidates the locked holding following the preflight read. */
    public static function prepared(Domain $d, array $records, \DateTimeImmutable $now): void
    {
        if (!$records) { return; }
        $p=$d->observation??[]; $sequence=($p['management_sequence']??0)+1; $p['management_sequence']=$sequence;
        foreach ($records as $kind=>$facts) {
            $key=$kind==='glue'?$facts['host']:$kind;
            $p['record_reads'][$key]=['identity'=>ObservationRecords::identity($d),'action'=>$kind==='glue'?'glue_update':$kind,
                'operation_key'=>'preflight','confirmed'=>true,'at'=>$now->format(DATE_ATOM),'sequence'=>$sequence,'facts'=>$facts];
        }
        $d->update(['observation'=>$p]);
    }
    public static function asOf(Domain $d, ?array $section=null): ?string
    {
        $section??=ObservationProjection::sections($d);
        return $section['glue_at']??ObservationRecords::current($d)['at']??null;
    }
}
