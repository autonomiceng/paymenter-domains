<?php
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
/** Bounded public record fields for a direct 422 response. Never persisted or flashed. */
final class OwnerRecordDraft
{
    public static function apply(OwnedDomain $d,string $action,#[\SensitiveParameter] array $p,bool $confirm=false): OwnedDomain
    {
        $cap=$action==='srv'?'srv':DomainManagement::capability($action); $sections=$d->management;
        if (!isset($sections[$cap])) { return $d; }
        $clean=static function(mixed $row,array $limits):array {
            $out=[]; $row=is_array($row)?$row:[];
            foreach ($limits as $field=>$limit) {
                $v=$row[$field]??'';
                $out[$field]=(is_string($v)||is_int($v))&&!preg_match('/[\x00-\x1f\x7f]/',(string)$v)?mb_strcut((string)$v,0,$limit,'UTF-8'):'';
            }
            return $out;
        };
        if (in_array($action,['dns','srv'],true)) {
            $limits=$action==='dns'?['name'=>253,'type'=>10,'address'=>2048,'mx_pref'=>5]:['service'=>14,'protocol'=>3,'priority'=>5,'weight'=>5,'port'=>5,'target'=>254];
            $rows=is_array($p['records']??null)?array_values(array_slice($p['records'],0,50)):[];
            $values=['records'=>array_map(static fn($row)=>$clean($row,$limits+['_delete'=>1,'_source'=>2]),$rows)];
            $digest=$p['preimage_digest']??null;
            if (is_string($digest)&&preg_match('/^[a-f0-9]{64}$/D',$digest)) { $values['digest']=$digest; }
        } elseif ($cap==='ds') { $values=['ds'=>$clean($p['ds']??[],['key_tag'=>5,'algorithm'=>5,'digest_type'=>5,'digest'=>64])]; }
        elseif ($cap==='glue') { $values=$clean($p,['host'=>253,'ip'=>64]); }
        elseif ($cap==='nameservers') { $values=['nameservers'=>is_string($p['nameservers']??null)?mb_strcut($p['nameservers'],0,4096,'UTF-8'):'','use_default'=>($p['use_default']??null)==='1'?'1':'']; }
        else { $values=[]; }
        $sections[$cap]['retry']=['action'=>$action,'values'=>$values,'confirm'=>$confirm];
        return new OwnedDomain(...[...get_object_vars($d),'management'=>$sections]);
    }
}
