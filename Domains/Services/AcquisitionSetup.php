<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Local purchase grammar shared by owner capture and the production pre-claim check. No transport. */
final class AcquisitionSetup
{
    public static function validate(Domain $d, Operation $op, array $servers, bool $privacy): array
    {
        if (!in_array($d->registrar_key,['enom','namesilo'],true)) { throw new SetupValidation(['nameservers']); }
        if ($privacy && ($d->registrar_key==='enom'||$d->tld==='us')) { throw new SetupValidation(['privacy']); }
        try {
            if ($d->registrar_key==='enom') {
                if ($op->action==='transfer' && $servers) { throw new \InvalidArgumentException; }
                $servers=array_values(\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers($servers));
            } else {
                $mapping='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\NameSilo\\Api\\PurchaseMapping';
                if ($op->action==='transfer') { $mapping::transfer($d->domain,'validation-only',$privacy,'1',$servers); }
                else { $mapping::register($d->domain,(int)$op->term,$privacy,'1',$servers); }
                $servers=array_map('strtolower',$servers);
            }
        } catch (\InvalidArgumentException) { throw new SetupValidation(['nameservers']); }
        return ['nameservers'=>$servers,'privacy'=>$privacy];
    }
    public static function ready(Operation $op): bool
    {
        if (!in_array($op->action,['register','transfer'],true)) { return true; }
        try { self::validate($op->domain,$op,$op->payload['nameservers']??[],$op->payload['privacy']??false); return true; }
        catch (\Throwable) { return false; }
    }
}
