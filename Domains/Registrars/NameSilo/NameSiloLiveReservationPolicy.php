<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Contracts\LiveReservationPolicy;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances;
/** Explicit deployment authority only. Feature acceptance and financial admission remain in the shared engines. */
final class NameSiloLiveReservationPolicy implements LiveReservationPolicy
{
    public function limits(AccountBinding $binding): array
    {
        if($binding->registrarKey!=='namesilo'||$binding->environment!==Environment::Live){throw new \DomainException('Live NameSilo binding required.');}
        $site=config('domains.namesilo_live.site');$parts=is_string($site)?parse_url($site):false;
        if(!$parts||($parts['scheme']??null)!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])
            ||isset($parts['query'])||isset($parts['fragment'])||strlen($site)>255||rtrim($site,'/')!==$site
            ||$site!==rtrim((string)config('app.url'),'/')){throw new \DomainException('Live NameSilo site unavailable.');}
        $account=Account::where('server_id',$binding->serverId)->sole();
        $binding->requireMatch(CatalogueAcceptances::binding($account));
        Server::whereKey($binding->serverId)->where('extension','Domains')->where('enabled',true)->sole();
        $rows=config('domains.namesilo_live.accounts',[]);$row=is_array($rows)?($rows[$account->stable_identity]??null):null;
        if($account->live_site!==$site||!is_array($row)||($row['fingerprint']??null)!==$binding->fingerprint
            ||($row['currency']??null)!=='USD'||($row['balance_funding']??null)!==true){throw new \DomainException('Live NameSilo account unavailable.');}
        return $row;
    }
    public function requireFeature(AccountBinding $binding,string $feature): void
    {
        $this->limits($binding);
        if(!in_array($feature,['catalogue','registration','transfer','renewal','management'],true)
            ||config('domains.namesilo_live.catalogue')!==true||config('domains.namesilo_live.'.$feature)!==true){throw new \DomainException('Live NameSilo feature off.');}
        if($feature!=='catalogue'&&!Account::where('server_id',$binding->serverId)->sole()->processing_enabled){throw new \DomainException('NameSilo processing off.');}
    }
}
