<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
/** Only the singleton default ID and normalized email leave contact inventory parsing. */
final class DefaultContact
{
    public static function fromReply(#[\SensitiveParameter] array $reply): array
    {
        $rows=$reply['contact']??null;if(is_array($rows)&&!array_is_list($rows)){$rows=[$rows];}
        if(!is_array($rows)||count($rows)>1000){throw new \DomainException;}$defaults=[];
        foreach($rows as $row){
            if(!is_array($row)||!in_array($row['default_profile']??null,[0,1,'0','1'],true)){throw new \DomainException;}
            if(in_array($row['default_profile'],[1,'1'],true)){$defaults[]=$row;}
        }
        if(count($defaults)!==1){throw new \DomainException;}$row=$defaults[0];$id=$row['contact_id']??null;
        if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,19}$/D',$id)){throw new \DomainException;}
        return ['contact_id'=>$id,'email'=>self::email($row['email']??null)];
    }
    public static function email(mixed $value): string
    {
        if(!is_string($value)||strlen($value)>320||!filter_var(trim($value),FILTER_VALIDATE_EMAIL)){throw new \DomainException;}
        return strtolower(trim($value));
    }
}
