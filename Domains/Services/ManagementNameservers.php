<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** A saved site choice fills ordinary nameservers; it never resets registrar DNS. */
final class ManagementNameservers
{
    public const MISSING='Site default nameservers are not available. Enter your own nameservers.';
    public static function registrar(Domain $d): string
    { return match($d->registrar_key){'enom'=>'Enom','namesilo'=>'NameSilo',default=>'Unknown'}; }
    public static function defaults(Domain $d,OwnerPorts $ports): array
    { return self::choice($d,$ports)['servers']; }
    /** Missing configuration is distinct from an unreadable configuration. Never fall back to registrar DNS. */
    public static function choice(Domain $d,OwnerPorts $ports): array
    {
        try {
            $servers=SiteDnsDefault::profile()?->nameservers??[];
            if(!$servers){return ['servers'=>[],'error'=>null];}
            $servers=OwnerNameservers::parse(implode("\n",$servers),false,$d->registrar_key);
            $ports->forDomain($d)->validate('nameservers',['nameservers'=>$servers],$d->domain);
            return ['servers'=>$servers,'error'=>null];
        } catch(\Throwable $error) {
            try{if(\Illuminate\Support\Facades\Cache::add('domains-dns-default-unavailable:'.$d->account_fingerprint,true,60)){
                \Illuminate\Support\Facades\Log::warning('Domains site DNS default unavailable.',['exception_class'=>$error::class]);
            }
            }catch(\Throwable){/* Diagnostics must not disable custom editing. */}
            return ['servers'=>[],'error'=>'We could not read the site default nameservers. Enter your own nameservers, or try again later.'];
        }
    }
}
