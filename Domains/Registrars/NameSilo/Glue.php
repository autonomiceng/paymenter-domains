<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Services\ManagementPayload;
/** The provider replaces a host's whole IPv4 set. Keep every address, including on readback. */
final class Glue
{
    public static function addresses(mixed $value): array
    {
        $ips=is_array($value)?$value:explode(',',ManagementPayload::text($value,220));
        if (!array_is_list($ips)||!$ips||count($ips)>13) { throw new \DomainException('Enter 1 to 13 public IPv4 addresses, separated by commas.'); }
        $out=[];
        foreach ($ips as $ip) {
            if (!is_string($ip)||!filter_var(trim($ip),FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) { throw new \DomainException('NameSilo glue records need IPv4 addresses. IPv6 is not supported.'); }
            $ip=trim($ip); ManagementPayload::publicIp($ip); $out[]=$ip;
        }
        $out=array_values(array_unique($out)); sort($out,SORT_STRING); return $out;
    }
    /** NameSilo's documented JSON repeats ip keys instead of using an array. Preserve every occurrence. */
    public static function preserveAddresses(string $body): string
    {
        $index=0;
        return preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"/s',static function($match)use(&$index,$body):string {
            $token=$match[0][0]; $end=$match[0][1]+strlen($token);
            if (!preg_match('/\\G\\s*:/',$body,$unused,0,$end)) { return $token; }
            $key=json_decode($token,true,24,JSON_THROW_ON_ERROR);
            if (str_starts_with($key,'__glue_ip_')) { throw new \DomainException('Glue list unavailable.'); }
            return $key==='ip'?'"__glue_ip_'.($index++).'"':$token;
        },$body,-1,$count,PREG_OFFSET_CAPTURE)??throw new \DomainException('Glue list unavailable.');
    }
    public static function records(array $reply, string $domain): array
    {
        if (!array_key_exists('hosts',$reply)) { throw new \DomainException('Glue list unavailable.'); }
        $rows=$reply['hosts']; if ($rows===null) { $rows=[]; }
        if (is_array($rows)&&isset($rows['host'])) { $rows=[$rows]; }
        if (!is_array($rows)||!array_is_list($rows)||count($rows)>50) { throw new \DomainException('Glue list unavailable.'); }
        $records=[];
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new \DomainException('Glue list unavailable.'); }
            $host=ManagementPayload::text($row['host']??null,253).'.'.$domain;
            $host=ManagementPayload::normalize('glue_delete',['host'=>$host],$domain,'namesilo')['host'];
            if (isset($records[$host])) { throw new \DomainException('Duplicate glue host.'); }
            $ips=[];
            foreach ($row as $key=>$value) {
                if ($key==='ip'||str_starts_with((string)$key,'__glue_ip_')) { $ips=[...$ips,...(is_array($value)?$value:[$value])]; }
            }
            $records[$host]=implode(', ',self::addresses($ips));
        }
        return $records;
    }
}
