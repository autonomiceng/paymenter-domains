<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Deployment authority for the built-in live composition. No implicit account or site. */
final class EnomLive
{
    public static function requested(): bool
    {foreach(['catalogue','registration','renewal','management'] as $action){if(config('domains.enom_live.'.$action)===true){return true;}}return false;}
    public static function site(): string
    {
        $site=config('domains.enom_live.site');$parts=is_string($site)?parse_url($site):false;
        if(!$parts||($parts['scheme']??null)!=='https'||empty($parts['host'])
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])
            ||strlen($site)>255||rtrim($site,'/')!==$site||$site!==rtrim((string)config('app.url'),'/')){
            throw new \DomainException('Live Enom requires the configured canonical HTTPS site.');
        }
        return $site;
    }
    public static function deployment(AccountBinding $b): bool
    {return $b->registrarKey==='enom'&&$b->environment===Environment::Live&&config('domains.enom_identity.environment')==='live';}
    public static function account(AccountBinding $b): array
    {
        if(!self::deployment($b)){
            throw new \DomainException('Live Enom account required.');
        }
        $site=self::site();$a=Account::where('server_id',$b->serverId)->sole();$uid=$a->stable_identity;
        $row=config('domains.enom_live.accounts',[])[$uid]??null;
        if(!is_array($row)||($row['fingerprint']??null)!==$b->fingerprint){throw new \DomainException('Live Enom account binding required.');}
        $b->requireMatch(CatalogueAcceptances::binding($a));
        if($a->live_site!==$site){throw new \DomainException('Live Enom site binding required.');}
        foreach(Account::where('registrar_key','enom')->where('environment','test')->get() as $test){
            if($test->stable_identity===$uid){throw new \DomainException('An Enom TEST account cannot be used live.');}
        }
        return $row;
    }
    public static function enabled(AccountBinding $b,string $action): bool
    {
        if(!in_array($action,['catalogue','registration','renewal','management'],true)||config('domains.enom_live.'.$action)!==true
            ||config('domains.enom_live.catalogue')!==true){return false;}
        try{self::account($b);return true;}catch(\Throwable){return false;}
    }
    public static function key(AccountBinding $b,string $testKey): string
    {return $b->environment===Environment::Live?str_replace('_test_','_live_',$testKey):$testKey;}
    public static function route(): string {return 'https://reseller.enom.com/interface.asp';}
    public static function facts(AccountBinding $b): array
    {
        $out=[];foreach(['catalogue','registration','renewal','management'] as $action){$out['Enom live '.$action]=self::enabled($b,$action)?'Deployment allows live '.$action.'. Independent live acceptance and processing are required.':'Off or account/site binding unavailable.';}
        $out['Enom live daily limits']=EnomLiveCalls::facts($b);$out['Enom live inbound transfer']='Unavailable';return $out;
    }
}
