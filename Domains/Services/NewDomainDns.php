<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Operation,DnsTemplate};
/** Site default for new registrations. Never creates a DNS zone.
 * Hosting-plan DNS zones are served by the hosting control panel (Plesk), provisioned by the hosting server
 * extension and never seen by this extension. DNS templates only name those nameservers.
 * Registrar DNS (Enom DNS, NameSilo DNSOwl) is used only while a domain points at the registrar's nameservers
 * and stops when the domain transfers out. */
final class NewDomainDns
{
    public static function profile(int $server): ?DnsTemplate
    { return self::selection($server)['profile']; }
    /** The one site choice is independent of the caller's registrar. @return array{chosen:bool,profile:?DnsTemplate} */
    public static function selection(int $server): array
    {
        $profile=SiteDnsDefault::profile();
        return ['chosen'=>$profile!==null,'profile'=>$profile];
    }
    public static function nameservers(int $server,string $action): array
    { return $action==='register'?(self::profile($server)?->nameservers??[]):[]; }
    /** Freeze the actual registration payload and its origin together; equality alone never proves a customer choice. */
    public static function capture(int $server,string $action): array
    {
        if($action!=='register' || !($profile=self::profile($server))){return [];}
        return ['nameservers'=>$profile->nameservers,'nameservers_origin'=>['source'=>'site_default',
            'profile_id'=>(int)$profile->id,'profile_revision'=>(int)$profile->revision]];
    }
    /** Captured choices survive site default changes; legacy editable orders may use the current template. */
    public static function forOperation(Operation $op): array
    {
        if($op->action!=='register'){return [];}
        if($saved=$op->payload['nameservers']??[]){return $saved;}
        $defaults=self::nameservers((int)$op->server_id,'register');
        try{if($defaults){self::validate($op->registrar_key,$defaults,$op->domain->domain);}}
        catch(\InvalidArgumentException){return [];}
        return $defaults;
    }
    public static function validate(string $registrar,array $servers,?string $domain=null): void
    {
        if($registrar==='enom'){\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers($servers);}
        else{\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\PurchaseMapping::nameservers($servers,$domain);}
    }
}
