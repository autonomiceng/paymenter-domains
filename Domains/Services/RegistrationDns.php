<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** A DNS correction cannot undo registration. Keep money and contact problems separate. */
final class RegistrationDns
{
    public static function corrections(Operation $op,array $observed=[]): array
    {
        $sections=$observed;
        foreach([$op->attempt['corrections']??[],$op->attempt['evidence']['registration']['corrections']??[]] as $saved){
            array_push($sections,...(array_is_list($saved)?$saved:array_keys($saved)));
        }
        return array_values(array_unique($sections));
    }
    public static function pending(?Operation $op,Domain $d): bool
    {
        if(!$op || $op->action!=='register' || $op->state!=='succeeded'
            || !in_array('nameservers',self::corrections($op,$op->policy['reconciliation']['observed_corrections']??[]),true)){return false;}
        $actual=ObservationProjection::nameservers($d);
        if($actual?->state==='observed'){
            $desired=$op->payload['nameservers']??[];$current=$actual->nameservers;sort($desired);sort($current);
            if($desired===$current){return false;}
        }
        // A later explicit customer or staff choice replaces the original registration's DNS intent.
        return !$d->operations()->where('id','>',$op->id)->where('action','nameservers')->where('state','succeeded')
            ->where('authority_generation',$d->authority_generation)->where('account_fingerprint',$d->account_fingerprint)->orderByDesc('id')->limit(100)->get()
            ->contains(fn($peer)=>!isset($peer->policy['management']['correction'])&&ManagementEvidence::resolved($peer,$d));
    }
    public static function registrarDns(Domain $d): bool
    {
        $actual=ObservationProjection::nameservers($d);
        return $d->registrar_key==='enom' && $actual?->state==='observed'
            && \Paymenter\Extensions\Servers\Domains\Registrars\Enom\ManagementRead::defaultDns($actual->nameservers);
    }
}
