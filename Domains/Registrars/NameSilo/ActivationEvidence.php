<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Contracts\LiveActivationEvidence;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,LocalAccountEnrollment};
/** Dormant local evidence adapter. Not registered with staff actions, factories or RuntimePorts. */
final class ActivationEvidence implements LiveActivationEvidence
{
    /** TEST accepts only the authenticated local lineage, never a Live account or an OTE label. */
    public static function testAccount(AccountBinding $binding): Account
    {
        if($binding->registrarKey!=='namesilo'||$binding->environment!==Environment::Test
            ||config('domains.namesilo_account.environment')!=='test'){throw new \DomainException('TEST NameSilo account required.');}
        $account=Account::where('server_id',$binding->serverId)->sole();
        $binding->requireMatch(AccountBindings::binding($account));
        app(AccountBindings::class)->credentialRevision($binding);
        return $account;
    }
    public function deployment(AccountBinding $binding): bool
    {return $binding->registrarKey==='namesilo'&&$binding->environment===Environment::Live&&config('domains.namesilo_account.environment')==='live';}
    public function site(): string
    {
        $site=config('domains.namesilo_live.site');$parts=is_string($site)?parse_url($site):false;
        if(!$parts||($parts['scheme']??null)!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])
            ||isset($parts['query'])||isset($parts['fragment'])||strlen($site)>255||rtrim($site,'/')!==$site
            ||$site!==rtrim((string)config('app.url'),'/')){throw new \DomainException('Live NameSilo site unavailable.');}
        return $site;
    }
    public function account(AccountBinding $binding): array
    {
        if(!$this->deployment($binding)){throw new \DomainException('Live NameSilo account required.');}
        $site=$this->site();$account=Account::where('server_id',$binding->serverId)->sole();
        $binding->requireMatch(AccountBindings::binding($account));
        Server::whereKey($binding->serverId)->where('extension','Domains')->where('enabled',true)->sole();
        $rows=config('domains.namesilo_live.accounts',[]);$row=is_array($rows)?($rows[$account->stable_identity]??null):null;
        if(!LocalAccountEnrollment::enrolled($account)||$account->live_site!==$site||!is_array($row)
            ||($row['fingerprint']??null)!==$binding->fingerprint||($row['currency']??null)!=='USD'||($row['balance_funding']??null)!==true){
            throw new \DomainException('Live NameSilo account unavailable.');
        }
        app(AccountBindings::class)->credentialRevision($binding);
        return $row;
    }
    public function enabled(AccountBinding $binding,string $feature): bool
    {
        if(!in_array($feature,['catalogue','registration','transfer','renewal','management'],true)
            ||config('domains.namesilo_live.catalogue')!==true||config('domains.namesilo_live.'.$feature)!==true){return false;}
        // Fresh acquisitions create an isolated contact through the management reservation.
        if(in_array($feature,['registration','transfer'],true)&&config('domains.namesilo_live.management')!==true){return false;}
        try{$this->account($binding);return true;}catch(\Throwable){return false;}
    }
    public function label(): string {return 'NameSilo';}
    public function configuration(Account $account): array
    {
        try{$revision=app(AccountBindings::class)->credentialRevision(AccountBindings::binding($account));}
        catch(\Throwable){$revision=null;} // A broken key must still permit local review/withdrawal.
        return [config('domains.namesilo_live'),config('domains.namesilo_account.environment'),$revision];
    }
    public function requireControls(array $evidence): void
    {
        foreach(['reject_invalid_contacts','restore_disabled','auto_replenishment_disabled'] as $control){
            if(($evidence['portal_controls'][$control]??null)!==true){
                throw new \DomainException('NameSilo portal controls Missing. Have your site admin retain dated evidence of rejected invalid contacts, disabled restoration and disabled auto-replenishment in the existing activation review.');
            }
        }
    }
    public function transferProofRequired(): bool {return false;}
    public function receiptContext(AccountBinding $binding): array
    {
        $this->account($binding);$revision=config('domains.namesilo_live.runtime_revision');
        if(!is_string($revision)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$/D',$revision)){
            throw new \DomainException('NameSilo runtime revision Missing. Ask your site admin to install the admitted runtime.');
        }
        return ['credential_revision'=>app(AccountBindings::class)->credentialRevision($binding),
            'deployment'=>hash('sha256',json_encode($this->configuration(Account::where('server_id',$binding->serverId)->sole()),JSON_THROW_ON_ERROR))];
    }
}
