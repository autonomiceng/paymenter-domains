<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CatalogueAcceptance, Environment};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Price, SalesPolicy, Tld};
use Paymenter\Extensions\Servers\Domains\Models\Account;

final class CatalogueAcceptances
{
    public const KEY = 'domains_catalogue_acceptance';
    /** Long lists are packed, short ones stay plain JSON. */
    private const PACKED = 'z:';
    private const CAPACITY = 32768;
    public static function load(AccountBinding $binding, bool $lock = false): CatalogueAcceptance
    {
        if ($lock && DB::transactionLevel() === 0) { throw new \LogicException('Acceptance lock requires a transaction.'); }
        $query = Server::findOrFail($binding->serverId)->settings()->where('key', self::KEY);
        if ($lock) { $query->lockForUpdate(); }
        try {
            $setting = $query->firstOrFail();
            if (!$setting->encrypted || !is_string($setting->value)) { throw new \DomainException; }
            $value = str_starts_with($setting->value, self::PACKED) ? gzinflate(base64_decode(substr($setting->value, strlen(self::PACKED)), true), 1048576) : $setting->value;
            $data = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            if (($data['fingerprint'] ?? null) !== $binding->fingerprint || ($data['currency'] ?? null) !== 'USD' || ($data['unit'] ?? null) !== 'exact_term_annual') { throw new \DomainException; }
            return new CatalogueAcceptance($binding, $data['revision'], EndingChoices::closeHidden($data['endings']),
                $data['registration_route'] ?? null, $data['registration_interval_seconds'] ?? 0);
        } catch (\Throwable) { throw new \DomainException('Catalogue acceptance is unavailable.'); }
    }
    /** Explicit acceptance/revocation by the future staff workflow; not automatic discovery. */
    public static function save(AccountBinding $binding, int $expectedRevision, array $endings): CatalogueAcceptance
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Acceptance owns its lock order.'); }
        return DB::transaction(static function () use ($binding, $expectedRevision, $endings): CatalogueAcceptance {
            $server = Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
            $account = Account::where('server_id', $binding->serverId)->lockForUpdate()->firstOrFail();
            $binding->requireMatch(self::binding($account));
            if (!$server->enabled || $server->extension !== 'Domains') { throw new \DomainException('Unavailable registrar server.'); }
            $setting = $server->settings()->where('key', self::KEY)->lockForUpdate()->first();
            $old = $setting ? self::load($binding, true) : null;
            if (($old?->revision ?? 0) !== $expectedRevision) { throw new \DomainException('Stale acceptance revision.'); }
            $next = new CatalogueAcceptance($binding, $expectedRevision + 1, $endings,
                $old?->registrationRoute, $old?->registrationIntervalSeconds ?? 0);
            self::saveLocked($next); return $next;
        });
    }
    /** Caller has checked current revision/repair evidence under its account review locks. */
    public static function saveLocked(CatalogueAcceptance $next): void
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Outer acceptance transaction required.'); }
        $binding = $next->binding;
        UpgradeGate::legacy();
        $server = Server::whereKey($binding->serverId)->lockForUpdate()->firstOrFail();
        $account = Account::where('server_id', $binding->serverId)->lockForUpdate()->firstOrFail();
        $binding->requireMatch(self::binding($account));
        if (!$server->enabled || $server->extension !== 'Domains') { throw new \DomainException('Unavailable registrar server.'); }
        $data = ['fingerprint' => $binding->fingerprint, 'revision' => $next->revision, 'endings' => $next->endings,
            'currency' => 'USD', 'unit' => 'exact_term_annual'];
        if ($next->registrationRoute !== null) {
            $data['registration_route'] = $next->registrationRoute;
            $data['registration_interval_seconds'] = $next->registrationIntervalSeconds;
        }
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::CAPACITY) { $encoded = self::PACKED.base64_encode(gzdeflate($encoded, 9)); }
        if (strlen($encoded) > self::CAPACITY) { throw new \DomainException('Acceptance exceeds encrypted settings capacity.'); }
        $setting = $server->settings()->where('key', self::KEY)->lockForUpdate()->first() ?? $server->settings()->make(['key' => self::KEY]);
        $setting->encrypted = true; $setting->type = 'string'; $setting->value = $encoded; $setting->save();
        StorefrontCatalogueCache::invalidateAfterCommit();
    }
    /** Caller owns the server/account mutex. Staff choices and system rule changes use the same narrowing and holder checks. */
    public static function changeLocked(Account $a, ?CatalogueAcceptance $old, array $changes): CatalogueAcceptance
    {
        if (DB::transactionLevel() !== 1) { throw new \LogicException('Outer acceptance transaction required.'); }
        UpgradeGate::legacy();
        $binding = self::binding($a);
        $old?->binding->requireMatch($binding);
        $names=Tld::where('server_id',$binding->serverId)->pluck('tld');
        SalesPolicy::whereIn('tld',$names)->orderBy('id')->lockForUpdate()->get();
        $tlds=Tld::where('server_id',$binding->serverId)->orderBy('id')->lockForUpdate()->get();
        $prices=Price::where('server_id',$binding->serverId)->orderBy('id')->lockForUpdate()->get();
        $endings=$old?->endings??[];
        $registrationRoute=$old?->registrationRoute;$registrationInterval=$old?->registrationIntervalSeconds??0;
        if(!$old&&Domain::where('server_id',$binding->serverId)->whereNotNull('reservation_name')->orderBy('id')->lockForUpdate()->get()->isNotEmpty()){throw new \DomainException('Repair must preserve all holder renewal acceptance.');}
        foreach($changes as $ending=>$change){
            $ending=(string)$ending;\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse('example.'.$ending,[$ending]);
            if($change!==null){
                if(EndingChoices::hidden($ending)){throw new \DomainException('This TLD is hidden.');}
                $terms=array_map(static fn($v)=>is_string($v)&&preg_match('/^(?:[1-9]|10)$/D',$v)?(int)$v:$v,$change['terms']??[]);
                if($terms===[]){throw new \DomainException('Choose at least one year.');}
                // Annual NameSilo prices prove no multiyear terms; CatalogueAcceptance refuses them.
                sort($terms);$endings[$ending]=['register'=>array_values(array_unique($terms)),'renew'=>[1],'transfer'=>($change['transfer']??false)?[1]:[],'registry'=>true,'renewal_safe'=>true];
                continue;
            }
            $holders=Domain::where(['server_id'=>$binding->serverId,'tld'=>$ending,'account_fingerprint'=>$a->account_fingerprint])->whereNotNull('reservation_name')->orderBy('id')->lockForUpdate()->get()->isNotEmpty();
            if($holders){
                if(!$old||!($endings[$ending]['renewal_safe']??false)||!in_array(1,$endings[$ending]['renew']??[],true)){throw new \DomainException('Holder renewal acceptance cannot be inferred.');}
                $endings[$ending]['register']=[];$endings[$ending]['transfer']=[];$endings[$ending]['registry']=false;
            }else{unset($endings[$ending]);}
        }
        // Selling needs a batch route and our request spacing. Both are fixed by the deployment, so record them rather than ask staff.
        $testRegistration=$binding->registrarKey==='enom'&&$binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Test
            &&(EnomTestRegistration::deploymentEnabled()||EnomTestTransfer::deploymentEnabled());
        if(array_filter($changes)!==[]&&(EnomLive::enabled($binding,'registration')||EnomLive::enabled($binding,'transfer')||$testRegistration)){
            $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
            if(!$protocol['batch_route']||$protocol['interval_seconds']<25){throw new \DomainException(match(true){!$testRegistration=>'Live Enom Purchase route acceptance is unavailable.',EnomTestTransfer::deploymentEnabled()=>'Enom TEST acquisition route acceptance is unavailable.',default=>'Enom TEST Purchase route acceptance is unavailable.'});}
            $registrationRoute=$protocol['route'];$registrationInterval=$protocol['interval_seconds'];
        }
        $revision=$old?->revision??0;
        foreach($prices as $price){$revision=max($revision,(int)($price->eligibility['catalogue_revision']??0));}
        $next=new CatalogueAcceptance($binding,$revision+1,$endings,$registrationRoute,$registrationInterval);
        CatalogueAcceptances::saveLocked($next);
        // A saved list only moves the revision. Terms kept in both lists keep their price evidence, so one change never holds other endings.
        foreach($prices as $price){$proof=$price->eligibility;
            if($old===null||($proof['catalogue_revision']??null)!==$old->revision||!in_array($price->term,$old->endings[$price->tld][$price->action]??[],true)||!in_array($price->term,$next->endings[$price->tld][$price->action]??[],true)){continue;}
            $price->fill(['eligibility'=>[...$proof,'catalogue_revision'=>$next->revision],'revision'=>$price->revision+1])->save();}
        // Narrow immediately; widening never imports prices, enables a TLD, or restores withdrawn actions.
        foreach($tlds as $row){$accepted=$next->endings[$row->tld]??null;
            $row->register_enabled=$row->register_enabled&&($accepted['registry']??false)&&!empty($accepted['register']);
            $row->transfer_enabled=$row->transfer_enabled&&($accepted['registry']??false)&&!empty($accepted['transfer']);
            if(array_key_exists($row->tld,$changes)&&!empty($accepted['register'])){$row->min_years=min($accepted['register']);$row->max_years=max($accepted['register']);}
            $row->save();}
        foreach(array_keys($changes) as $ending){TestCatalogue::withdrawCarts($binding,(string)$ending,true);}
        return $next;
    }
    public static function binding(Account $account): AccountBinding
    { return AccountBindings::binding($account); }
}
