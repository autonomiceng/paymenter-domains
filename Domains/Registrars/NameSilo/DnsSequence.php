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
            $after=self::after($name,$record,$id);
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
    /**
     * A replaced identical request keeps the rows its earlier changes added. A desired row without an ID takes the ID
     * that one earlier add confirmed, only while that exact row is still in the inventory.
     */
    public static function correlate(string $name,array $inventory,array $desired,array $confirmed): array
    {
        $now=self::records($inventory);$named=[];$made=[];
        foreach ($desired as $record) { if (is_array($record) && isset($record['record_id'])) { $named[$record['record_id']]=true; } }
        foreach ($confirmed as $step) {
            $delta=$step['input']['delta']??null;
            if (!is_array($delta) || ($delta['kind']??null)!=='add' || !is_array($step['before']??null) || !is_array($step['after']??null)) { continue; }
            try { $new=array_diff_key(self::records($step['after']),self::records($step['before'])); } catch (\DomainException) { continue; }
            if (count($new)!==1) { continue; }
            $row=reset($new);$id=$row['record_id'];$wanted=$delta['after'];unset($wanted['record_id']);$actual=$row;unset($actual['record_id']);
            if ($wanted===$actual && ($now[$id]??null)===$row && !isset($named[$id])) { $named[$id]=true;$made[]=[$id,$wanted]; }
        }
        foreach ($desired as $i=>$record) {
            if (!$made || !is_array($record) || array_key_exists('record_id',$record) || !in_array($record['type']??null,Dns::STANDARD,true)) { continue; }
            try { $after=self::after($name,$record,null); } catch (\DomainException) { continue; }
            foreach ($made as $k=>[$id,$wanted]) {
                if ($wanted===$after) { $desired[$i]['record_id']=$id;unset($made[$k]);break; }
            }
        }
        return $desired;
    }
    private static function after(string $name,array $record,mixed $id): array
    {
        $wire=DnsMapping::add($name,$record)->parameters();
        return ['name'=>$wire['rrhost']===''?'@':$wire['rrhost'],'type'=>$record['type'],'address'=>$wire['rrvalue'],
            'mx_pref'=>$wire['rrdistance']??10,...($id===null?[]:['record_id'=>$id]),'ttl'=>$wire['rrttl']];
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
