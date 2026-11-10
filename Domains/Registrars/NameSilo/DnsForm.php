<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Services\OwnerFormValidation;
/** The shared records editor over a saved complete inventory. Provider IDs stay server-side; rows we can't edit stay as read, with their read TTL. */
final class DnsForm
{
    /** The documented provider default, sent explicitly for a new row. */
    public const TTL=7207;
    /** @return array{records:list<array>,fixed:list<array>,digest:string} Editable rows in saved order, read-only rows, and the list they came from. */
    public static function view(array $inventory): array
    {
        $records=[];$fixed=[];
        foreach (self::rows($inventory) as $r) {
            if (in_array($r['type'],Dns::STANDARD,true)) { $records[]=['name'=>$r['name'],'type'=>$r['type'],'address'=>$r['address'],'mx_pref'=>$r['mx_pref']]; }
            else { $fixed[]=['name'=>$r['name'],'type'=>$r['type'],'address'=>$r['address'],'ttl'=>$r['ttl']??null]; }
        }
        return ['records'=>$records,'fixed'=>$fixed,'digest'=>hash('sha256',json_encode(DnsSequence::records($inventory),JSON_THROW_ON_ERROR))];
    }
    /** Why each read-only type stays as it is, and what to do instead. */
    public static function notes(array $fixed): array
    {
        $types=array_unique(array_column($fixed,'type'));sort($types);
        return array_map(fn($type)=>$type.' records can’t be changed here yet'.($type==='SRV'?', because we can’t read their priority':'').'. Saving keeps them as they are. To change one, contact support.',$types);
    }
    /** A row from a saved place keeps its ID and TTL unless its type changed; omitted read-only rows are kept by the plan. */
    public static function payload(array $inventory, #[\SensitiveParameter] array $clean, array $sources, ?string $digest): array
    {
        if (!is_string($digest) || !hash_equals(self::view($inventory)['digest'],$digest)) { throw new \DomainException('Current records required.'); }
        $saved=array_values(array_filter(self::rows($inventory),fn($r)=>in_array($r['type'],Dns::STANDARD,true)));$seen=[];$desired=[];
        foreach (array_values($clean) as $i=>$row) {
            $source=$sources[$i]??'';$old=null;
            if ($source!=='') {
                if (!is_string($source) || !preg_match('/^(?:0|[1-9][0-9]?)$/D',$source) || !isset($saved[(int)$source]) || isset($seen[$source])) { throw new OwnerFormValidation(['dns.records']); }
                $seen[$source]=true;$old=$saved[(int)$source];
            }
            $type=strtoupper($row['type']);$keep=$old!==null && $old['type']===$type;
            $desired[]=['name'=>$row['name'],'type'=>$type,'address'=>$row['address'],'mx_pref'=>$type==='MX'?$row['mx_pref']:10,
                ...($keep?['record_id'=>$old['record_id']]:[]),'ttl'=>$keep?$old['ttl']:self::TTL];
        }
        return ['records'=>$desired,'preimage'=>self::rows($inventory)];
    }
    private static function rows(array $inventory): array
    { DnsSequence::records($inventory); return array_values($inventory['records']); }
}
