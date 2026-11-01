<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\Schema;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsContext,DnsQuery};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,ManagedPublicObservation};
/** Public DNS diagnostic on the existing delegation turn. No registrar transport or automatic corrections. */
final class GlueDrift
{
    public static function check(Domain $d, DnsReader $reader, DnsContext $context, int $cursor, int $now, int $interval): array
    {
        $identity=ObservationRecords::identity($d); $known=ObservationProjection::sections($d)['known_glue']??[];
        $hosts=array_keys($known); if (!$hosts) { return []; }
        $offset=$cursor%count($hosts); $hosts=[...array_slice($hosts,$offset),...array_slice($hosts,0,$offset)]; $results=[];
        foreach ($hosts as $host) {
            $saved=array_map('trim',explode(',',$known[$host])); $actual=[]; $complete=true;
            $types=array_unique(array_map(fn($ip)=>str_contains($ip,':')?'AAAA':'A',$saved));
            if ($context->used()+count($types)>DnsContext::MAX_QUERIES||!$context->live()) { break; }
            foreach ($types as $type) {
                try { $answer=$reader->read(new DnsQuery($host,$type),$context); }
                catch (\Throwable) { $complete=false; break; }
                if (!$answer->known()||$answer->query->key()!==$host.'|'.$type||!$context->live()) { $complete=false; break; }
                $actual=[...$actual,...$answer->values()];
            }
            $normalize=static fn($ips)=>array_map(static fn($ip)=>inet_ntop(inet_pton($ip)),$ips);
            $saved=$normalize($saved); $actual=$normalize($actual); sort($saved); sort($actual);
            $results[$host]=['saved'=>$known[$host],'addresses'=>$actual,'drift'=>$complete?$saved!==$actual:null,'at'=>$now,'until'=>$now+2*$interval];
        }
        return ['identity'=>$identity,'hosts'=>$results];
    }
    public static function current(Domain $d, array $check, ?array $known=null): array
    {
        if (($check['identity']??null)!==ObservationRecords::identity($d)) { return []; }
        $known??=ObservationProjection::sections($d)['known_glue']??[];
        $check['hosts']=array_filter($check['hosts']??[],static fn($row,$host)=>($row['saved']??null)===($known[$host]??null),ARRAY_FILTER_USE_BOTH);
        return $check;
    }
    public static function warnings(Domain $d, ?int $now=null): array
    {
        $now??=time();
        $known=ObservationProjection::sections($d)['known_glue']??[];
        if (!$known||!Schema::hasTable('domains_managed_public_observations')) { return []; }
        $seen=[]; $warnings=[];
        $observations=ManagedPublicObservation::where('domain_id',$d->id)->where('user_id',$d->user_id)
            ->where('account_fingerprint',$d->account_fingerprint)->where('generation',$d->authority_generation)
            ->where('check_kind','delegation')->where('completed_at','<=',$now)->where('completed_at','>',$now-43200)->orderByDesc('id')->limit(100)->get();
        foreach ($observations as $observation) {
            try { $check=self::current($d,$observation->facts['glue']??[],$known); }
            catch (\Throwable) { return []; }
            foreach ($check['hosts']??[] as $host=>$row) {
                if (isset($seen[$host])) { continue; } $seen[$host]=true;
                if (($row['drift']??null)!==true||$row['at']>$now||$row['until']<=$now) { continue; }
                $warnings[]=$host.': registrar has '.$row['saved'].'. Public DNS has '.(implode(', ',$row['addresses'])?:'no address').'. If public DNS is right, edit the glue record.';
            }
        }
        return $warnings;
    }
}
