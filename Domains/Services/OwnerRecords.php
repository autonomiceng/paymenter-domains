<?php
// Enom 444f31c DomainController DNS/glue projection; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Whitelist proven records; DNS/SRV/DS unknown stays absent. Glue is explicitly partial history. No I/O. */
final class OwnerRecords
{
    /** Existing IDs are looked up from proven rows; browser indexes grant no provider identity. */
    public static function restoreSrv(Domain $d,array $p): array
    {
        $raw=ObservationProjection::sections($d)['srv']['facts']['records']??[];
        $known=array_map(fn($r)=>ManagementPayload::srv($r),$raw);
        $known=self::ordered('srv',$known);
        $seen=[];
        foreach ($p['records'] as $i=>&$row) {
            $source=$p['record_sources'][$i]??'';
            if ($source==='') { continue; }
            try { $index=ManagementPayload::integer($source,49); }
            catch (\Throwable) { throw new OwnerFormValidation(['srv.records']); }
            if (!isset($known[$index])||isset($seen[$index])) { throw new OwnerFormValidation(['srv.records']); }
            $seen[$index]=true;
            if (isset($known[$index]['host_id'])) { $row['host_id']=$known[$index]['host_id']; }
        } unset($row);
        unset($p['record_sources']);return $p;
    }
    /** Presentation and SRV source indexes use the same order; evidence digests keep their canonical order. */
    private static function ordered(string $kind,array $rows): array
    {
        if (!in_array($kind,['dns','srv'],true)) { return $rows; }
        $field=$kind==='dns'?'name':'service';
        usort($rows,static function($a,$b)use($kind,$field):int {
            $left=ManagementPayload::canonical($kind,[$a])[0];$right=ManagementPayload::canonical($kind,[$b])[0];
            return strnatcmp($left[$field],$right[$field]) ?: strcmp(json_encode($left),json_encode($right));
        });return $rows;
    }
    public static function forDomain(Domain $d): array
    {
        $facts=ObservationProjection::sections($d); $out=[]; $inventory=ObservationRecords::current($d);
        foreach (['dns','srv','ds'] as $kind) {
            try {
                if (in_array($kind,['dns','srv'],true)&&(!$inventory||($inventory['families'][$kind]['state']??null)!=='complete')) { continue; }
                $rows=$facts[$kind]['facts']['records']??null;
                if (!is_array($rows)||!array_is_list($rows)||count($rows)>50) { continue; }
                $out[$kind]=self::ordered($kind,ManagementPayload::canonical($kind,$rows));
            } catch (\Throwable) {}
        }
        $out['glue']=[];
        foreach (array_slice($facts['known_glue']??[],0,50,true) as $host=>$ip) {
            try { $out['glue'][]=ManagementPayload::normalize('glue_add',['host'=>$host,'ip'=>$ip],$d->domain,'enom'); }
            catch (\Throwable) {}
        }
        return $out;
    }
}
