<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
/** Independent management opt-in. DNS tools retain their separate server gate. */
final class EnomTestManagement
{
    public const KEY='domains_enom_test_management';
    public static function enabled(AccountBinding $b): bool
    { return EnomLive::enabled($b,'management') || (config('domains.enom_writes.management')===true && TestCatalogue::testDeployment($b) && TestCatalogue::enabled($b)); }
    public static function reason(): string
    {
        if(config('domains.enom_writes.management')!==true){return 'TEST management is off in deployment configuration.';}
        if(config('domains.enom_identity.environment')!=='test'){return 'TEST management refused: the deployment identity environment is not test. Live and unset environments cannot use TEST management.';}
        if(config('domains.enom_catalogue.enabled')!==true){return 'TEST management refused: the deployment TEST catalogue is off.';}
        return 'Deployment allows TEST management. Review TEST management acceptance below and enable processing separately. Nameservers, registrar lock and contacts require current acceptance, processing, an active domain and service and resolved prior changes. DNS, SRV and DS also require a DNS tools server setting set by the deployment; DNS and SRV editors need a complete, current read of existing domain records. Other management actions remain unavailable.';
    }
    public static function protocol(?AccountBinding $b=null): array
    { return ['route'=>$b?->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?EnomLive::route():'https://resellertest.enom.com/interface.asp','commands'=>array_map(static fn($c)=>$c->value,[\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ManagementCommand::Nameservers,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ManagementCommand::Lock,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ManagementCommand::Contacts,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ManagementCommand::Dns,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ManagementCommand::Srv,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ManagementCommand::DsAdd,\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ManagementCommand::DsDelete]),'interval'=>\Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::REQUEST_SECONDS]; }
    public static function accepted(AccountBinding $b): bool
    {
        if(!self::enabled($b)){return false;}
        try {
            $s=\App\Models\Server::findOrFail($b->serverId)->settings()->where('key',EnomLive::key($b,self::KEY))->first();
            return $s?->encrypted===true && json_decode($s->value,true,8,JSON_THROW_ON_ERROR)===['fingerprint'=>$b->fingerprint,...self::protocol($b)];
        }catch(\Throwable){return false;}
    }
    public static function review(StaffSettingsReview $r,array $data): void
    {
        $r->local(function()use($r,$data):void {
            $a=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$r->serverId)->sole();$b=CatalogueAcceptances::binding($a);$label=$b->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?'Live':'TEST';
            if(str_starts_with($r->action,'live_')!==($b->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live)||!self::enabled($b)){throw new \DomainException($label.' management deployment is unavailable.');}
            $s=\App\Models\Server::findOrFail($b->serverId)->settings()->firstOrNew(['key'=>EnomLive::key($b,self::KEY)]);
            if(($data['accept']??false)===true){
                if(($data['verified']??false)!==true){throw new \DomainException('Independent '.$label.' management review required.');}
                $protocol=self::protocol($b);if($protocol['interval']<25){throw new \DomainException($label.' management deployment is unavailable.');}
                $value=['fingerprint'=>$b->fingerprint,...$protocol];
            }else{$value=null;}
            $s->encrypted=true;$s->type='string';$s->value=json_encode($value,JSON_THROW_ON_ERROR);$s->save();
        });
    }
    public static function requireAction(AccountBinding $b,string $action): void
    {
        if(!self::accepted($b)||!in_array($action,['nameservers','lock','contacts','dns','srv','ds_add','ds_delete'],true)){
            throw new \DomainException(($b->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?'Live':'TEST').' management acceptance required.');
        }
    }
}
