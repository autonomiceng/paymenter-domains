<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Browser input grammar only. Provider and operation validation still apply. */
final class OwnerNameservers
{
    public static function lines(#[\SensitiveParameter] mixed $text): array
    {
        return is_string($text)?array_values(array_filter(array_map('trim',preg_split('/\r\n|\n|\r/',$text)),static fn($v)=>$v!=='')):[];
    }
    public static function parse(#[\SensitiveParameter] mixed $text,bool $defaults,string $registrar,?string $purchaseDomain=null): array
    {
        $max=$registrar==='enom'?12:13;
        $fail=static function(string $kind,?int $row=null)use($defaults,$max):never {
            throw new OwnerFormValidation(['nameservers'],['kind'=>$kind,'row'=>$row,'defaults'=>$defaults,'max'=>$max]);
        };
        // Native ConvertEmptyStringsToNull turns an empty textarea into null.
        if ($text!==null&&!is_string($text)) { $fail('format'); }
        $lines=self::lines($text);
        if (!$lines&&$defaults) { return []; }
        if (count($lines)<2) { $fail('few'); }
        if (count($lines)>$max) { $fail('many'); }
        $out=[];
        foreach ($lines as $i=>$line) {
            $host=strtolower(str_ends_with($line,'.')?substr($line,0,-1):$line);
            if (strlen($host)>253||str_ends_with($host,'.')||!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($host,'.')
                ||filter_var($host,FILTER_VALIDATE_IP)||($registrar==='namesilo'&&preg_match('/\.[0-9]+$/D',$host))) { $fail('hostname',$i); }
            if ($registrar==='namesilo'&&$purchaseDomain===null&&!preg_match('/[a-z]/',substr($host,strrpos($host,'.')+1))) { $fail('hostname',$i); }
            if ($purchaseDomain!==null&&$registrar==='namesilo') {
                foreach (explode('.',$host) as $label) { if (str_starts_with($label,'xn--')) { $fail('hostname',$i); } }
                if ($host===$purchaseDomain||str_ends_with($host,'.'.$purchaseDomain)) { $fail('external',$i); }
            }
            if (in_array($host,$out,true)) { $fail('duplicate',$i); }
            $out[]=$host;
        }
        return $out;
    }
    public static function message(array $reason,#[\SensitiveParameter] mixed $text,string $domain): string
    {
        $line=self::lines($text)[$reason['row']??-1]??'';
        $value=mb_strcut($line,0,254,'UTF-8');
        $duplicate=strtolower(str_ends_with($value,'.')?substr($value,0,-1):$value);
        return match($reason['kind']??null) {
            'few'=>'Enter at least 2 nameservers, one per line'.(($reason['defaults']??false)?', or leave this blank to use '.\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::nameservers().'.':'.'),
            'many'=>'Enter no more than '.$reason['max'].' nameservers.',
            'hostname'=>$value." isn't a valid hostname. Use a name like ns1.example.com.",
            'duplicate'=>$duplicate.' is listed more than once.',
            'external'=>'Use nameservers outside '.$domain.', such as ns1.example.com.',
            'format'=>'Enter nameservers as hostnames, one per line.',
            default=>'These nameservers cannot be used for this domain. Contact support.',
        };
    }
}
