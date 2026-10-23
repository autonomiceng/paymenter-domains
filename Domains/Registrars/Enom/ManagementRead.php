<?php
// Enom 444f31c EnomApi/DomainOperations readback; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ProtocolResult;
final class ManagementRead
{
    public static function privacy(ProtocolResult $r): array
    {
        $p = []; foreach (['allowed','exists','enabled','autorenew'] as $k) { $p[$k] = Driver::flag($r->value('getwppsinfo.wpps'.$k)); }
        $expiry = Expiry::read(new ProtocolResult($r->status, null, ['expirationdate' => $r->value('getwppsinfo.wppsexpdate')]));
        $p['expiry'] = $expiry ? substr($expiry->value, 0, 10) : null; $p['cost'] = Pricing::annual($r->value('getwppsinfo.wppsprice'));
        return $p;
    }
    public static function nameservers(ProtocolResult $r): array
    {
        $raw=$r->value('dns'); $rows=is_string($raw)?[$raw]:$raw;
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>12){throw new \DomainException('Nameservers malformed.');}
        if (!$rows) { throw new \DomainException('Nameservers unknown.'); }
        return array_values(Registration::nameservers($rows));
    }
    public static function defaultDns(array $rows): bool
    { return $rows !== [] && count(array_filter($rows, fn ($v) => str_ends_with($v, '.name-services.com'))) === count($rows); }
    public static function records(ProtocolResult $r, string $kind): array
    {
        if ($kind === 'ds') { return self::ds($r); }
        if($r->status!=='success'){throw new \DomainException('Record section unknown.');}
        if($kind==='dns'){$raw=$r->value('host');if($raw===null){if(Driver::flag($r->value('domainservices.hostrecords'))!==true){throw new \DomainException('DNS section missing.');}$raw=[];}elseif($raw===''){throw new \DomainException('DNS records malformed.');}}
        else{$box=$r->value('srv-records');if($box===''){$raw=[];}elseif(is_array($box)&&!array_is_list($box)&&array_keys($box)===['srv']&&is_array($box['srv'])){$raw=$box['srv'];}else{throw new \DomainException('SRV section missing or malformed.');}}
        $rows = self::rows($raw); $out=[];
        foreach ($rows as $v) {
            if (!is_array($v)) { throw new \DomainException('Malformed records.'); }
            // Provider targets may omit the root dot; desired input still uses the strict validator.
            if ($kind === 'dns' && is_string($v['type'] ?? null) && in_array(strtoupper($v['type']), ['CNAME', 'MX'], true)
                && is_string($v['address'] ?? null) && !str_ends_with($v['address'], '.')) { $v['address'] .= '.'; }
            $out[] = $kind === 'dns' ? ManagementInput::host(['name'=>$v['name']??null,'type'=>$v['type']??null,'address'=>$v['address']??null,'mx_pref'=>$v['mxpref']??10])
                : ManagementInput::srv(['service'=>ltrim($v['hostname']??$v['name']??'', '_'),'protocol'=>strtolower(ltrim($v['protocol']??'', '_')),
                    'target'=>$v['address']??null,'priority'=>$v['priority']??null,'weight'=>$v['weight']??null,'port'=>$v['port']??null,
                    ...isset($v['hostid']) ? ['host_id'=>$v['hostid']] : []]);
        }
        return $out;
    }
    private static function rows(mixed $v): array
    { if ($v === null || $v === '') { return []; } if (!is_array($v)) { throw new \DomainException('Malformed list.'); } $r=array_is_list($v)?$v:[$v]; if(count($r)>50){throw new \DomainException('Record limit.');} return $r; }
    private static function tuple(array $v): array
    {
        if (count($v)!==4 || array_diff(array_keys($v),['algorithm','digest','digesttype','keytag'])) { throw new \DomainException('Malformed DS.'); }
        return ManagementInput::ds(['algorithm'=>$v['algorithm'],'digest'=>$v['digest'],'digest_type'=>$v['digesttype'],'key_tag'=>$v['keytag']]);
    }
    private static function ds(ProtocolResult $r): array
    {
        $n=$r->value('dnssecdatacount'); $box=$r->value('dnssecdata');
        if ($n === null && $box === null) {
            if ($r->value('success')!==null && Driver::flag($r->value('success'))!==true) { throw new \DomainException('DS unknown.'); }
            return [self::tuple(['algorithm'=>$r->value('algorithm'),'digest'=>$r->value('digest'),'digesttype'=>$r->value('digesttype'),'keytag'=>$r->value('keytag')])];
        }
        if (!is_string($n) || !preg_match('/^(?:0|[1-9][0-9]?)$/D',$n) || (int)$n>50 || Driver::flag($r->value('success'))!==true
            || !is_array($box) || array_diff(array_keys($box),['result','keydata']) || !is_array($box['result']??null)
            || array_diff(array_keys($box['result']),['responsecode','responsemessage']) || ($box['result']['responsecode']??null)!=='200'
            || (isset($box['result']['responsemessage']) && !is_string($box['result']['responsemessage']))) { throw new \DomainException('DS shape unknown.'); }
        foreach (['algorithm','digest','digesttype','keytag'] as $k) { if ($r->value($k)!==null) { throw new \DomainException('Mixed DS shape.'); } }
        if ($n==='0') { if (array_key_exists('keydata',$box)) { throw new \DomainException('Contradictory zero DS.'); } return []; }
        $rows=self::rows($box['keydata']??null); if(count($rows)!==(int)$n){throw new \DomainException('DS count mismatch.');}
        $out=[]; foreach($rows as $v){$t=self::tuple($v); if(in_array($t,$out,true)){throw new \DomainException('Duplicate DS.');} $out[]=$t;} return $out;
    }
    public static function privacyOrder(ProtocolResult $r,string $id,string $name): bool
    {
        $h=$r->value('order');$rows=$r->value('order.orderdetail');if(!is_array($rows)){return false;}$rows=array_is_list($rows)?$rows:[$rows];
        if($r->status!=='success'||!is_array($h)||($h['orderid']??null)!==$id||Driver::flag($h['result']??null)!==true||count($rows)!==1||!is_array($rows[0])){return false;}
        $d=$rows[0];foreach(['description','status','orderstatus','quantity'] as $k){if(!is_string($d[$k]??null)){return false;}}
        return strtolower($d['description'])===$name&&strtolower($d['status'])==='successful'&&strtolower($d['orderstatus'])==='closed'&&$d['quantity']==='1';
    }
    public static function glue(ProtocolResult $r, string $host): ?string
    {
        if ($r->value('host_absent')===true) { return null; }
        $ip=$r->value('checknsstatus.ipaddress'); $name=$r->value('checknsstatus.nsname');
        if ($r->status!=='success' || $r->value('nschecksuccess')!=='1' || !is_string($ip) || !filter_var($ip,FILTER_VALIDATE_IP)
            || !is_string($name) || strtolower(rtrim($name,'.'))!==$host) { throw new \DomainException('Glue status unknown.'); } return $ip;
    }
}
