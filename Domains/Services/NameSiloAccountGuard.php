<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Server;
use Illuminate\Support\Facades\{DB,Log};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DefaultContact;
/** Default-contact continuity only; this does not establish provider account identity. */
final class NameSiloAccountGuard
{
    public const EXPECTED='domains_namesilo_expected_default_contact_email';
    public const PIN='domains_namesilo_default_contact_fingerprint';
    public static function check(AccountBinding $binding,RequestContext $ctx,#[\SensitiveParameter] \Closure $exchange): void
    {
        OperationClaims::outside();$binding->requireMatch($ctx->binding);
        try {
            if($binding->registrarKey!=='namesilo'){throw new \DomainException;}
            $server=Server::whereKey($binding->serverId)->where('extension','Domains')->where('enabled',true)->firstOrFail();
            $expected=DefaultContact::email($server->settings()->where('key',self::EXPECTED)->first()?->value);
            $origin=($binding->environment===Environment::Live
                ?\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Live
                :\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\Environment::Sandbox)->origin()
                .($ctx->origin===RequestOrigin::Background?'/apibatch':'/api');
            $body=$ctx->withinRead($binding,fn($remaining)=>$exchange($origin,'contactList',[],min(25000,$remaining)));
            if(!is_string($body)||strlen($body)>1048576){throw new \DomainException;}
            $data=json_decode($body,true,24,JSON_THROW_ON_ERROR);
            if(($data['request']['operation']??null)!=='contactList'||!in_array($data['reply']['code']??null,[300,'300'],true)){throw new \DomainException;}
            $contact=DefaultContact::fromReply($data['reply']);
            if($contact['email']!==$expected){throw new \DomainException;}
            $fingerprint=['binding'=>$binding->fingerprint,...$contact];
            // No network under locks. Serialize first-use pins and recheck concurrent setting/account edits.
            DB::transaction(static function()use($binding,$expected,$fingerprint):void {
                $s=Server::whereKey($binding->serverId)->where('extension','Domains')->where('enabled',true)->lockForUpdate()->firstOrFail();
                $a=Account::where('server_id',$binding->serverId)->lockForUpdate()->firstOrFail();
                $binding->requireMatch(CatalogueAcceptances::binding($a));
                if(DefaultContact::email($s->settings()->where('key',self::EXPECTED)->lockForUpdate()->first()?->value)!==$expected){throw new \DomainException;}
                $pin=$s->settings()->where('key',self::PIN)->lockForUpdate()->first();
                if($pin){
                    if(!$pin->encrypted||json_decode($pin->value,true,8,JSON_THROW_ON_ERROR)!==$fingerprint){throw new \DomainException;}
                }else{
                    $s->settings()->create(['key'=>self::PIN,'value'=>json_encode($fingerprint,JSON_THROW_ON_ERROR),'encrypted'=>true,'type'=>'string']);
                }
            });
        }catch(ReadDeferred $deferred){throw $deferred;}
        catch(\Throwable $e){
            try{Log::warning('NameSilo account guard held a request.',['exception_class'=>$e::class]);}
            catch(\Throwable){ /* Diagnostics must not bypass the hold. */ }
            throw new WriteHeld('namesilo_account_guard');
        }
    }
}
