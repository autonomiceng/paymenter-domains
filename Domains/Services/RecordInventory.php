<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Dns;
/** Provider-specific validation preserves full NameSilo inventories, including opaque CAA/SRV. */
final class RecordInventory
{
    public static function family(string $kind,mixed $family,string $registrar,string $name): array
    {
        $unknown=['state'=>'unknown','records'=>[]];
        if (!is_array($family)||!in_array($family['state']??null,['complete','partial'],true)
            ||!is_array($family['records']??null)||!array_is_list($family['records'])) { return $unknown; }
        $state=$family['state'];$rows=[];$seen=[];
        foreach (array_slice($family['records'],0,50) as $row) {
            try {
                if (!is_array($row)) { throw new \DomainException; }
                if ($registrar==='namesilo'&&$kind==='dns') {
                    $host=Dns::host($name,$row['name']??'');
                    $wire=['record_id'=>$row['record_id']??null,'type'=>$row['type']??null,'host'=>$host===''?$name:$host.'.'.$name,
                        'value'=>$row['address']??null,'ttl'=>$row['ttl']??null,'distance'=>$row['type']==='SRV'?($row['distance']??null):($row['mx_pref']??10)];
                    $parsed=Dns::inventory(json_encode(['request'=>['operation'=>'dnsListRecords'],'reply'=>['code'=>300,'detail'=>'inventory','resource_record'=>[$wire]]],JSON_THROW_ON_ERROR),$name);
                    if ($parsed['state']!=='complete'||$parsed['records']!==[$row]||isset($seen[$row['record_id']])) { throw new \DomainException; }
                    $seen[$row['record_id']]=true;
                } else { ManagementPayload::canonical($kind,[$row]); }
                $rows[]=$row;
            } catch (\Throwable) { $state='partial'; }
        }
        if (count($family['records'])>50) { $state='partial'; }
        return ['state'=>$state,'records'=>$rows];
    }
    public static function settings(mixed $settings): array
    {
        $out=[];
        foreach (['nameservers','contacts','flags'] as $kind) {
            $f=is_array($settings)?($settings[$kind]??[]):[];
            $out[$kind]=is_array($f)&&in_array($f['state']??null,['complete','partial','unknown'],true)&&is_array($f['facts']??null)
                ? array_intersect_key($f,array_flip(['state','facts','at','last_good'])):['state'=>'unknown','facts'=>[]];
        }
        return $out;
    }
    public static function incomplete(array $record): bool
    {
        foreach (RecordFormat::FAMILIES as $kind) {
            $legacyUnsupported=!isset($record['expected_families'])&&($record['identity']['registrar']??null)==='namesilo'&&$kind!=='glue';
            if ($legacyUnsupported||($record['expected_families'][$kind]??'supported')==='unsupported') { continue; }
            $f=$record['families'][$kind]??[];
            if (($kind==='glue'&&($record['identity']['registrar']??null)==='enom')
                ? !in_array($f['state']??null,['complete','partial','unknown'],true)||($f['read_failed']??false)
                : ($f['state']??null)!=='complete') { return true; }
        }
        foreach ($record['settings']??[] as $f) { if (($f['state']??null)!=='complete') { return true; } }
        return false;
    }
}
