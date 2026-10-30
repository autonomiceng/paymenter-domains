<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Illuminate\Support\Facades\DB;
/** Built-in deployment policy. Staff owns catalogue acceptance and processing. */
final class EnomTestRegistration
{
    public static function enabled(AccountBinding $binding): bool
    { return EnomLive::enabled($binding,'registration') || (self::deploymentEnabled() && TestCatalogue::testDeployment($binding) && TestCatalogue::enabled($binding)); }
    /** Catalogue authority can observe a retained sealed attempt after every write opt-in is removed. */
    public static function recoveryEnabled(AccountBinding $binding): bool
    {
        if(!TestCatalogue::enabled($binding)){return false;}
        foreach(Operation::where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint)->whereIn('state',['pending','uncertain','running'])->cursor() as $op){
            try{if(($op->attempt['seal']??null)==='sealed'){return true;}}catch(\Throwable){continue;}
        }
        return false;
    }
    public static function deploymentEnabled(): bool
    { return config('domains.enom_writes.registration')===true && config('domains.enom_identity.environment')==='test' && config('domains.enom_catalogue.enabled')===true || config('domains.enom_identity.environment')==='live' && config('domains.enom_live.registration')===true; }
    public static function applies(Operation $operation): bool
    { return self::deploymentEnabled()&&$operation->action==='register'&&$operation->registrar_key==='enom'
        &&in_array($operation->state,['queued','awaiting_payment'],true); }
    /** Keep a previously accepted TEST account preview-only if its identity mode is later changed. */
    public static function checkoutPreviewOnly(AccountBinding $binding): bool
    {
        if(EnomLive::deployment($binding)){return true;}
        if($binding->registrarKey!=='enom'||$binding->environment!==\Paymenter\Extensions\Servers\Domains\Data\Environment::Test){return false;}
        if(TestCatalogue::testDeployment($binding)){return true;}
        $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
        return self::protocolEvidence($binding,$protocol,false);
    }
    public static function reason(): string
    {
        return config('domains.enom_writes.registration')!==true?'TEST registration is off in deployment configuration.'
            :(config('domains.enom_identity.environment')!=='test'?'TEST registration refused: the deployment identity environment is not test. Live and unset environments cannot use TEST registration.'
                :(config('domains.enom_catalogue.enabled')!==true?'TEST registration refused: the deployment TEST catalogue is off.'
                    :'Deployment allows TEST registration. The account needs at least one TLD saved under What you sell, processing on, and a successful USD balance read (Check balance) under an hour old when the worker runs.'));
    }
    /** Local predicates are checked before the worker claim; provider availability still uses the normal preflight. */
    public static function localHoldReason(AccountBinding $binding, Operation $op): ?string
    {
        if(!in_array($op->action,['register','transfer'],true)||$op->registrar_key!=='enom'||!($op->action==='transfer'?EnomTestTransfer::enabled($binding):self::enabled($binding))){return $op->action==='transfer'?'enom_test_transfer_setup_required':'enom_test_setup_required';}
        $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
        if(!$protocol['batch_route']||$protocol['interval_seconds']<25||!self::protocolEvidence($binding,$protocol,false)){return 'write_acceptance_required';}
        try {
            $accepted=CatalogueAcceptances::load($binding);$domain=\Paymenter\Extensions\Servers\Domains\Models\Domain::findOrFail($op->domain_id);
            $parts=\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse($domain->domain,array_keys($accepted->endings));
            $row=$accepted->endings[$parts['tld']]??null;
            if(!$row||!in_array((int)$op->term,$row[$op->action],true)||$row['renew']!==[1]||!$row['renewal_safe']||!$row['registry']){return 'write_acceptance_required';}
        } catch(\Throwable){return 'write_acceptance_required';}
        return self::balanceEvidence($binding,false)?null:($op->action==='transfer'?'enom_test_transfer_usd_balance_required':'usd_balance_required');
    }
    /** The worker would buy this name once released. Local facts only. */
    public static function releaseSends(Operation $op,?\Paymenter\Extensions\Servers\Domains\Models\Account $account): bool
    { $check=self::afterRelease($op,$account);return $check!==null&&$check['hold']===null&&$account->processing_enabled; }
    /** The worker would hold this straight back: its balance read is over an hour old. */
    public static function releaseNeedsBalance(Operation $op,?\Paymenter\Extensions\Servers\Domains\Models\Account $account): bool
    { return in_array(self::afterRelease($op,$account)['hold']??null,['usd_balance_required','enom_test_transfer_usd_balance_required'],true); }
    /** Null when the request skips the send check. */
    private static function afterRelease(Operation $op,?\Paymenter\Extensions\Servers\Domains\Models\Account $account): ?array
    {
        if(!$account){return null;}
        $queued=clone $op;$queued->state='queued';
        if(!self::applies($queued)&&!EnomTestTransfer::applies($queued)){return null;}
        try{return ['hold'=>self::localHoldReason(CatalogueAcceptances::binding($account),$op)];}catch(\Throwable){return null;}
    }
    /** Build account-bound ports without decrypting credentials or sending provider requests. */
    public static function factory(AccountBinding $binding): \Closure
    {
        if (!self::enabled($binding) && !EnomTestRenewal::enabled($binding) && !EnomTestManagement::enabled($binding) && !EnomTestTransfer::enabled($binding) && !self::recoveryEnabled($binding)) { throw new \DomainException(self::reason()); }
        // USD comes only from the existing audited manual/preflight balance reads, bound to this fingerprint.
        // Acceptance uses this environment's endpoint and the shared worker request interval.
        $protocolAcceptance=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
        if(!$protocolAcceptance['batch_route']||$protocolAcceptance['interval_seconds']<25){throw new \DomainException('TEST registration acceptance evidence is unavailable.');}
        $usdProbe=static fn(AccountBinding $current,bool $lock):bool=>$binding->matches($current)&&self::balanceEvidence($current,$lock);
        $protocolProbe=static fn(AccountBinding $current,bool $lock,?\Paymenter\Extensions\Servers\Domains\Data\Query $query=null):bool=>$binding->matches($current)&&self::protocolEvidence($current,$protocolAcceptance,$lock)&&($query?->action!==\Paymenter\Extensions\Servers\Domains\Data\Action::Transfer||EnomTestTransfer::enabled($current));
        return app(AccountBindings::class)->enomRuntimeFactory($binding,app(Clock::class),new WriteFeatures(registration:self::enabled($binding),transfer:EnomTestTransfer::enabled($binding),renewal:EnomTestRenewal::enabled($binding),management:EnomTestManagement::enabled($binding)),
            new WriteAcceptance($binding,true,$protocolAcceptance['batch_route'],$protocolAcceptance['interval_seconds'],null,$usdProbe,$protocolProbe),
            EnomTestRenewal::enabled($binding)?EnomTestRenewal::acceptance($binding):new RenewalAcceptance($binding),app(TransferPrecheck::class),EnomTestManagement::enabled($binding),true);
    }
    private static function protocolEvidence(AccountBinding $binding,array $protocol,bool $lock): bool
    {
        if($binding->registrarKey!=='enom'||$binding->environment!==\Paymenter\Extensions\Servers\Domains\Data\Environment::Test&&!EnomLive::enabled($binding,'catalogue')){return false;}
        try {
            $accepted=CatalogueAcceptances::load($binding,$lock);
            return $protocol['batch_route']===true&&$accepted->registrationRoute===$protocol['route']
                &&$accepted->registrationIntervalSeconds===$protocol['interval_seconds'];
        } catch(\Throwable){return false;}
    }
    private static function balanceEvidence(AccountBinding $binding,bool $lock): bool
    {
        if($binding->registrarKey!=='enom'||$binding->environment!==\Paymenter\Extensions\Servers\Domains\Data\Environment::Test&&!EnomLive::enabled($binding,'catalogue')){return false;}
        try {
            $q=DB::table(BalanceSchema::TABLE)->where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint);
            if($lock){$q->lockForUpdate();}$row=$q->first();$now=app(Clock::class)->now()->getTimestamp();
            return $row!==null&&in_array($row->source,['manual','preflight'],true)&&$row->last_error_code===null&&$row->currency==='USD'&&$row->amount!==null
                &&$row->read_at!==null&&(int)$row->read_at<=$now&&(int)$row->read_at>=$now-3600&&(int)$row->attempted_at===(int)$row->read_at;
        } catch(\Throwable){return false;}
    }
}
