<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Enom inbound transfers reuse the audited catalogue route, annual terms and USD predicates.
 * enabled() and accepted() gate new work; Live captured attempts reconcile under catalogue authority. */
final class EnomTestTransfer
{
    public const KEY='domains_enom_test_transfer';
    public static function enabled(AccountBinding $b): bool
    { return EnomLive::enabled($b,'transfer')||(self::deploymentEnabled()&&TestCatalogue::testDeployment($b)&&TestCatalogue::enabled($b)); }
    public static function protocol(?AccountBinding $b=null): array
    { return ['route'=>$b?->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?EnomLive::route():'https://resellertest.enom.com/interface.asp','command'=>\Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\RegistrationCommand::Transfer->value,'interval'=>\Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::REQUEST_SECONDS]; }
    public static function accepted(AccountBinding $b): bool
    {
        if(!self::enabled($b)){return false;}
        try {
            $s=\App\Models\Server::findOrFail($b->serverId)->settings()->where('key',EnomLive::key($b,self::KEY))->first();
            return $s?->encrypted===true&&json_decode($s->value,true,8,JSON_THROW_ON_ERROR)===['fingerprint'=>$b->fingerprint,...self::protocol($b)];
        }catch(\Throwable){return false;}
    }
    public static function review(StaffSettingsReview $r,array $data): void
    {
        $r->local(function()use($r,$data):void {
            $b=CatalogueAcceptances::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$r->serverId)->sole());
            $live=$b->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live;$label=$live?'Live':'TEST';
            if(str_starts_with($r->action,'live_')!==$live||!self::enabled($b)){throw new \DomainException($label.' transfer deployment is unavailable.');}
            $value=null;
            if(($data['accept']??false)===true){
                if(($data['verified']??false)!==true){throw new \DomainException('Independent '.$label.' transfer review required.');}
                $value=['fingerprint'=>$b->fingerprint,...self::protocol($b)];
            }
            $s=\App\Models\Server::findOrFail($b->serverId)->settings()->firstOrNew(['key'=>EnomLive::key($b,self::KEY)]);
            $s->encrypted=true;$s->type='string';$s->value=json_encode($value,JSON_THROW_ON_ERROR);$s->save();
            if($live){StorefrontCatalogueCache::invalidateAfterCommit();}
        });
    }
    /** @return list<string> TLDs with Transfers on in What you sell, once the switch is on. */
    public static function endings(int $serverId): array
    {
        try{
            $b=CatalogueAcceptances::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$serverId)->sole());
            return self::accepted($b)?array_map('strval',array_keys(array_filter(CatalogueAcceptances::load($b)->endings,static fn(array $row)=>$row['transfer']!==[]))):[];
        }catch(\Throwable){return [];}
    }
    public static function deploymentEnabled(): bool
    { return config('domains.enom_writes.transfer')===true&&config('domains.enom_identity.environment')==='test'&&config('domains.enom_catalogue.enabled')===true
        ||config('domains.enom_identity.environment')==='live'&&config('domains.enom_live.transfer')===true; }
    public static function applies(Operation $op): bool
    { return self::deploymentEnabled()&&$op->action==='transfer'&&$op->registrar_key==='enom'&&in_array($op->state,['queued','awaiting_payment','awaiting_code'],true); }
    public static function reason(): string
    {
        if(config('domains.enom_writes.transfer')!==true){return 'TEST inbound transfer is off in deployment configuration.';}
        if(config('domains.enom_identity.environment')!=='test'){return 'TEST inbound transfer refused: the deployment identity environment is not test. Live and unset environments cannot use TEST transfers.';}
        if(config('domains.enom_catalogue.enabled')!==true){return 'TEST inbound transfer refused: the deployment TEST catalogue is off.';}
        return 'Deployment allows TEST inbound transfer through TP_CreateOrder. Review catalogue acceptance for annual transfers, registry details, the fixed TEST route and request interval; separately enable processing and the transfer TLD. The owner transfer code is required. Preflight reads the USD balance before sending. Status and accepted charge history must confirm completion. No provider-supported TEST transfer fixture has been accepted.';
    }
}
