<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\DnsMapping;
/** Pure provider deltas and unique independent readback. No grants, queue or sender. */
final class DnsSequence
{
    public static function plan(string $name, array $inventory, array $desired): array
    {
        $before=self::records($inventory);
        if (!array_is_list($desired) || count($desired)>50) { throw new \DomainException('DNS record limit reached.'); }
        $seen=[];$updates=[];$adds=[];
        foreach ($desired as $record) {
            if (!is_array($record)) { throw new \DomainException('Invalid DNS record.'); }
            $id=$record['record_id']??null;$old=$id===null?null:($before[$id]??null);
            if ($id!==null && (!$old || isset($seen[$id]))) { throw new \DomainException('Current unique DNS record required.'); }
            if ($id!==null) { $seen[$id]=true; }
            if ($old && !in_array($old['type'],Dns::STANDARD,true)) {
                if ($old!==$record) { throw new \DomainException('This DNS record cannot be changed.'); } continue;
            }
            $wire=DnsMapping::add($name,$record)->parameters();
            $after=['name'=>$wire['rrhost']===''?'@':$wire['rrhost'],'type'=>$record['type'],'address'=>$wire['rrvalue'],
                'mx_pref'=>$wire['rrdistance']??10,...($id===null?[]:['record_id'=>$id]),'ttl'=>$wire['rrttl']];
            if ($old) {
                DnsMapping::update($name,$old,$after);
                if ($after!==$old) { $updates[]=['kind'=>'update','before'=>$old,'after'=>$after]; }
            } else { $adds[]=['kind'=>'add','after'=>$after]; }
        }
        $deletes=[];
        foreach ($before as $id=>$old) {
            if (!isset($seen[$id]) && in_array($old['type'],Dns::STANDARD,true)) { $deletes[]=['kind'=>'delete','before'=>$old]; }
        }
        $steps=[...$updates,...$deletes,...$adds];
        if (!$steps || count($steps)>50 || count($before)-count($deletes)+count($adds)>50) { throw new \DomainException('No bounded DNS changes to save.'); }
        return $steps;
    }
    public static function mapping(string $name,array $delta): DnsMapping
    {
        return match($delta['kind']??null) {
            'add'=>DnsMapping::add($name,$delta['after']),
            'update'=>DnsMapping::update($name,$delta['before'],$delta['after']),
            'delete'=>DnsMapping::delete($name,$delta['before']['record_id']),
            default=>throw new \DomainException('Invalid DNS delta.'),
        };
    }
    /** Accept exactly one attributable delta, retaining every unrelated row, ID and TTL. */
    public static function confirm(array $before,array $observed,array $delta,?string $ack): ?array
    {
        try { $old=self::records($before);$next=self::records($observed); }
        catch (\DomainException) { return null; }
        $kind=$delta['kind']??null;
        if (in_array($kind,['update','delete'],true)) {
            $id=$delta['before']['record_id'];
            if (($old[$id]??null)!==$delta['before'] || isset($next[$id])) { return null; }
            unset($old[$id]);
        }
        if ($kind!=='delete') {
            $candidates=array_diff_key($next,$old);
            if (count($candidates)!==1) { return null; }
            $record=reset($candidates);$id=$record['record_id'];
            if ($ack!==null && $ack!==$id) { return null; }
            $wanted=$delta['after'];unset($wanted['record_id']);$actual=$record;unset($actual['record_id']);
            if ($wanted!==$actual) { return null; }
            unset($next[$id]);
        }
        return $old===$next ? $observed : null;
    }
    /** Order-independent comparison by observed ID; do not infer completeness from an array alone. */
    public static function records(array $inventory): array
    {
        if (($inventory['state']??null)!=='complete' || !is_array($inventory['records']??null)
            || !array_is_list($inventory['records']) || count($inventory['records'])>50) { throw new \DomainException('Complete DNS inventory required.'); }
        $records=[];
        foreach ($inventory['records'] as $r) {
            if (!is_array($r) || !isset($r['record_id']) || isset($records[$r['record_id']])) { throw new \DomainException('Unique DNS inventory required.'); }
            Dns::id($r['record_id']);$records[$r['record_id']]=$r;
        }
        ksort($records);return $records;
    }
}
