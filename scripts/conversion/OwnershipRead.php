<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand,ResponseParser};
/** The converter and collector accept exactly the same ownership proof. */
final class OwnershipRead
{
    public static function parse(array $d,array $a,#[\SensitiveParameter] array $e): array
    {
        $xml=$e['xml']??'';$r=(new ResponseParser)->parse(ReadCommand::Info,is_string($xml)?$xml:'');
        $i=$r->value('getdomaininfo',[]);$n=$i['domainname']??null;$s=$i['status']??[];
        $name=is_array($n)?($n['#text']??null):$n;$id=is_array($n)?($n['@attributes']['domainnameid']??null):($i['domainnameid']??null);$owner=$s['belongs-to']??null;
        if(is_array($owner)){$owner=$owner['#text']??null;}$expiry=$s['expiration']??null;
        if($r->status!=='success'||$name!==$d['domain']||!is_string($owner)||strtolower($owner)!==$a['uid']||!is_string($id)||!preg_match('/^[1-9][0-9]{0,19}$/D',$id)
            ||(isset($i['domainnameid'])&&$i['domainnameid']!==$id)||!is_string($expiry)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$expiry)
            ||($e['account_fingerprint']??null)!==$a['old']){throw new \DomainException('Positive offline ownership/identity read required.');}
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$expiry);if(!$date||$date->format('Y-m-d')!==$expiry){throw new \DomainException('Precise accepted expiry required.');}
        return ['id'=>$id,'expiry'=>$expiry];
    }
}
