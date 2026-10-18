<?php
// Enom 444f31c DomainController record inputs; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Browser adaptation only. The existing driver and engine revalidate and authorize the request. */
final class OwnerRecordInput
{
    public static function payload(string $action, #[\SensitiveParameter] array $p, string $name): array
    {
        $errors=[];
        $check=static function(string $field, \Closure $validate)use(&$errors):void {
            try { $validate(); } catch (\Throwable) { $errors[]=$field; }
        };
        if (in_array($action,['dns','srv'],true)) {
            $rows=$p['records']??null; $clean=[]; $sources=[];
            if (!is_array($rows)||!array_is_list($rows)||count($rows)>50) { throw new OwnerFormValidation([$action.'.records.count']); }
            foreach ($rows as $i=>$row) {
                if (!is_array($row)) { $errors[]=$action.'.records.'.$i; continue; }
                if (($row['_delete']??null)==='1') { continue; }
                $source=$row['_source']??'';
                $row=array_diff_key($row,['_delete'=>true,'_source'=>true,'host_id'=>true]);
                if (!array_filter($row,static fn($v)=>$v!==''&&$v!==null)) { continue; }
                $base=$action==='dns'?['name'=>'@','type'=>'A','address'=>'8.8.8.8','mx_pref'=>10]
                    :['service'=>'sip','protocol'=>'tcp','target'=>'sip.example.com.','priority'=>0,'weight'=>0,'port'=>443];
                $before=count($errors);
                foreach (array_keys($base) as $field) {
                    $test=$base; $test[$field]=$row[$field]??null;
                    if ($action==='dns'&&$field==='address') { $test['type']=$row['type']??''; }
                    if ($action==='dns'&&$field==='type'&&in_array($test[$field],['AAAA','CNAME','MX','URL','FRAME'],true)) {
                        $test['address']=match($test[$field]) {'AAAA'=>'2606:4700:4700::1111','URL','FRAME'=>'https://example.com',default=>'example.com.'};
                    }
                    if ($action==='dns'&&$field==='mx_pref') { if (($row['type']??'')!=='MX') { continue; } $test['type']='MX';$test['address']='mail.example.com.'; }
                    $check($action.'.records.'.$i.'.'.$field,static fn()=> $action==='dns'?ManagementPayload::host($test):ManagementPayload::srv($test));
                }
                if (count($errors)===$before) { $check($action.'.records.'.$i,static fn()=> $action==='dns'?ManagementPayload::host($row):ManagementPayload::srv($row)); }
                $clean[]=$row; $sources[]=$source;
            }
            if (!$clean||count($clean)>50) { $errors[]=$action.'.records.count'; }
            $digest=$p['preimage_digest']??null;
            if (!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest)) { $errors[]=$action.'.records.preimage'; }
            $result=['records'=>$clean,'preimage_digest'=>$digest];
            if ($action==='srv') { $result['record_sources']=$sources; }
        } elseif (str_starts_with($action,'ds_')) {
            $ds=is_array($p['ds']??null)?$p['ds']:[];
            foreach (['key_tag','algorithm','digest_type'] as $f) {
                $check('ds.'.$f,static function()use($ds,$f):void {
                    $v=ManagementPayload::integer($ds[$f]??null);
                    if ($f==='algorithm'&&!in_array($v,[3,5,7,8,10,12,13,14],true)||$f==='digest_type'&&!in_array($v,[1,2],true)) { throw new \DomainException; }
                });
            }
            $digestTuple=[...$ds,'algorithm'=>13,'key_tag'=>0];
            $check('ds.digest',static fn()=>ManagementPayload::ds($digestTuple)); $result=['ds'=>$ds];
        } else {
            $host=$p['host']??'';
            $check('glue.host',static fn()=>ManagementPayload::normalize('glue_delete',['host'=>$host],$name,'enom'));
            $result=['host'=>$host];
            if ($action!=='glue_delete') {
                $ip=$p['ip']??'';
                $check('glue.ip',static fn()=>ManagementPayload::publicIp(ManagementPayload::text($ip,64)));$result['ip']=$ip;
            }
        }
        if ($errors) { throw new OwnerFormValidation(array_values(array_unique($errors))); }
        return $result;
    }
}
