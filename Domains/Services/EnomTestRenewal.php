<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
/** Independent renewal opt-in. Catalogue renewal-safe acceptance remains mandatory. */
final class EnomTestRenewal
{
    public const KEY='domains_enom_test_renewal';
    public static function enabled(AccountBinding $b): bool
    { return EnomLive::enabled($b,'renewal') || (config('domains.enom_writes.renewal')===true && TestCatalogue::testDeployment($b) && TestCatalogue::enabled($b)); }
    public static function reason(): string
    {
        if(config('domains.enom_writes.renewal')!==true){return 'TEST renewal is off in deployment configuration.';}
        if(config('domains.enom_identity.environment')!=='test'){return 'TEST renewal refused: the deployment identity environment is not test. Live and unset environments cannot use TEST renewal.';}
        if(config('domains.enom_catalogue.enabled')!==true){return 'TEST renewal refused: the deployment TEST catalogue is off.';}
        return 'Deployment allows TEST renewal. Review TEST renewal acceptance below, keep annual renewal accepted for each ending, and enable processing separately. Renewal billing and owner renewal requests are separate server settings set by the deployment. Grace and redemption renewals remain unavailable.';
    }
    public static function protocol(?AccountBinding $b=null): array
    { return ['route'=>$b?->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?EnomLive::route():'https://resellertest.enom.com/interface.asp','command'=>\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\RegistrationCommand::Renew->value,'interval'=>\Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::REQUEST_SECONDS]; }
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
            if(str_starts_with($r->action,'live_')!==($b->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live)||!self::enabled($b)){throw new \DomainException($label.' renewal deployment is unavailable.');}
            $s=\App\Models\Server::findOrFail($b->serverId)->settings()->firstOrNew(['key'=>EnomLive::key($b,self::KEY)]);
            if(($data['accept']??false)===true){
                if(($data['verified']??false)!==true){throw new \DomainException('Independent '.$label.' renewal review required.');}
                $protocol=self::protocol($b);if($protocol['command']!=='Extend'||$protocol['interval']<25){throw new \DomainException($label.' renewal deployment is unavailable.');}
                $value=['fingerprint'=>$b->fingerprint,...$protocol];
            }else{$value=null;}
            $s->encrypted=true;$s->type='string';$s->value=json_encode($value,JSON_THROW_ON_ERROR);$s->save();
        });
    }
    public static function acceptance(AccountBinding $b): RenewalAcceptance
    { return new RenewalAcceptance($b,true,true,self::protocol($b)['interval'],false,static fn(AccountBinding $current):bool=>$b->matches($current)&&self::accepted($current)); }
}
