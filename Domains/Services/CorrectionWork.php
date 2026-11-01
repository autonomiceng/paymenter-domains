<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\PrivacyMode;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** Deterministic, nonfinancial corrections to frozen details. Sealed requests are only observed. */
final class CorrectionWork
{
    private static function privacy(\Paymenter\Extensions\Servers\Domains\Models\Operation $op,ManagementProtocol $driver): array
    {
        $desired=$op->action==='transfer'?($op->attempt['transfer_admission']['privacy']??null):($op->payload['privacy']??false);
        return $desired===false?[['privacy_disable',[]]]:($desired===true&&$driver->capabilities()->privacy===PrivacyMode::Free?[['privacy_enable',[]]]:[]);
    }
    public static function produce(Domain $seed,DomainManagement $engine,ManagementProtocol $driver): ?\Paymenter\Extensions\Servers\Domains\Models\Operation
    {
        OperationClaims::outside();$plan=DB::transaction(function()use($seed,$driver):?array{
            $l=HoldingLocks::take($seed);$d=$l['domain'];
            foreach($l['operations'] as $original){
                $required=array_unique([...array_keys($original->attempt['corrections']??[]),
                    ...($original->attempt['evidence']['registration']['corrections']??[]),
                    ...($original->policy['reconciliation']['observed_corrections']??[]),
                    ...($original->attempt['reconciliation']['observed_corrections']??[]),
                    ...($original->safe_code==='provider_auto_renew_unconfirmed'?['auto_renew']:[])]);
                foreach($required as $section){
                    $inputs=match($section){
                        'auto_renew'=>[['auto_renew_off',[]]],
                        'nameservers'=>[['nameservers',['nameservers'=>$original->payload['nameservers']??[]]]],
                        'contacts'=>array_merge(...array_map(fn($role)=>array_map(fn($action)=>[$action,['role'=>$role,'contact'=>$original->payload['contacts'][$role]??[]]],$d->registrar_key==='namesilo'?['contact_profile','contacts']:['contacts']),ContactDetails::ROLES)),
                        // A paid privacy purchase needs its own invoice; never buy it as an ancillary correction.
                        'privacy'=>self::privacy($original,$driver),
                        default=>[],
                    };
                    foreach($inputs as $index=>[$action,$input]){
                        if(!ManagementCorrections::pending($l,$action,$original->operation_key)){continue;}
                        $nonce=hash('sha256','correction:'.$original->operation_key.':'.$section.':'.$index);
                        $existing=$l['operations']->firstWhere('operation_key','manage:'.$d->id.':'.$nonce);
                        if($existing){
                            if($existing->state==='rejected'||($existing->state==='succeeded'&&ManagementCorrections::resolvedFor($existing,$original,$d))){continue;}
                            return ['existing'=>$existing]; // Refusal/uncertainty never creates a second write.
                        }
                        // Missing frozen details stay unresolved, but cannot starve other proved corrections.
                        try{ManagementCorrections::require($l,$action,$driver->validate($action,$input,$d->domain),$original->operation_key);}
                        catch(\DomainException){continue;}
                        return compact('action','input','nonce','original')+['user'=>$l['user']];
                    }
                }
            }
            return null;
        });
        if(!$plan){return null;}
        return $plan['existing']??$engine->request($plan['user'],(int)$seed->id,$plan['action'],$plan['input'],$plan['nonce'],$driver,
            correction:$plan['original']->operation_key);
    }
}
