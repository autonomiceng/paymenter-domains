<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{ActivationEvidence,TestRuntime};
/** Reviewed switches for the existing NameSilo runtime. No provider sends or identity claims. */
final class NameSiloFeatureSettings
{
    public static function enabled(AccountBinding $b,string $feature): bool
    {return $b->environment===Environment::Test?TestRuntime::enabled($b,$feature):(new ActivationEvidence)->enabled($b,$feature);}
    public static function stored(AccountBinding $b,string $feature): bool
    {
        try{
            $s=Server::findOrFail($b->serverId)->settings()->where('key','domains_namesilo_'.$feature)->first();
            // Preserve the admitted TEST runtime's defaults until staff deliberately change a feature.
            if(!$s){return $b->environment===Environment::Test&&self::enabled($b,$feature);}
            $v=$s->encrypted?json_decode($s->value,true,8,JSON_THROW_ON_ERROR):null;
            return ($v['fingerprint']??null)===$b->fingerprint&&($v['credential_revision']??null)===app(AccountBindings::class)->credentialRevision($b)&&($v['enabled']??null)===true;
        }catch(\Throwable){return false;}
    }
    public static function accepted(AccountBinding $b,string $feature): bool
    {return self::enabled($b,$feature)&&self::stored($b,$feature)&&($b->environment===Environment::Test||EnomLiveActivation::current($b));}
    /** A retained executor cannot outlive a staff switch change. */
    public static function stamp(AccountBinding $b): string
    {return hash('sha256',json_encode(Server::findOrFail($b->serverId)->settings()->whereIn('key',array_map(static fn($f)=>'domains_namesilo_'.$f,['renewal','management','transfer']))->orderBy('key')->pluck('value','key')->all(),JSON_THROW_ON_ERROR));}
    public static function review(StaffSettingsReview $r,array $data,string $feature): void
    {
        if(!in_array($feature,['renewal','management','transfer'],true)||!in_array($r->action,['test_'.$feature,'live_'.$feature,'activate'],true)){throw new \DomainException('NameSilo feature review required.');}
        $r->local(static function()use($r,$data,$feature):void {
            $b=CatalogueAcceptances::binding(Account::where('server_id',$r->serverId)->sole());$on=($data['accept']??false)===true;
            $live=$b->environment===Environment::Live;
            if($b->registrarKey!=='namesilo'||(str_starts_with($r->action,'live_')||$r->action==='activate')!==$live
                ||($on&&!self::enabled($b,$feature))){throw new \DomainException('NameSilo feature is off on this site.');}
            if($on){
                EnomLiveActivation::requireFeature($r,$b,$feature);
                if(!$live&&($data['verified']??false)!==true){throw new \DomainException('Independent TEST feature review required.');}
            }
            Server::findOrFail($b->serverId)->settings()->updateOrCreate(['key'=>'domains_namesilo_'.$feature],['encrypted'=>true,'type'=>'string',
                'value'=>json_encode(['fingerprint'=>$b->fingerprint,'credential_revision'=>$on?app(AccountBindings::class)->credentialRevision($b):null,'enabled'=>$on],JSON_THROW_ON_ERROR)]);
            if($feature==='renewal'){RenewalBillingSettings::setSwitch($b->serverId,$on);}
            StorefrontCatalogueCache::invalidateAfterCommit();
        });
    }
}
