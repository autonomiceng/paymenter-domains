<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Illuminate\Support\Facades\DB;
/** Reviewed TEST-only deployment policy. Staff still owns catalogue acceptance and processing. */
final class EnomTestRegistration
{
    public static function enabled(AccountBinding $binding): bool
    { return self::deploymentEnabled() && TestCatalogue::enabled($binding); }
    public static function deploymentEnabled(): bool
    { return config('domains.enom_writes.registration')===true && config('domains.enom_identity.environment')==='test' && config('domains.enom_catalogue.enabled')===true; }
    public static function applies(Operation $operation): bool
    { return self::deploymentEnabled()&&$operation->action==='register'&&$operation->registrar_key==='enom'
        &&in_array($operation->state,['queued','awaiting_payment'],true); }
    /** Keep a previously accepted TEST account preview-only if its identity mode is later changed. */
    public static function checkoutPreviewOnly(AccountBinding $binding): bool
    {
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
                    :'Deployment allows TEST registration. Catalogue acceptance must include the Enom TEST Purchase route and request interval; the account also needs accepted catalogue terms, processing on, and a successful USD balance read (Read balance) under an hour old when the worker runs.'));
    }
    /** Local predicates are checked before the worker claim; provider availability still uses the normal preflight. */
    public static function localHoldReason(AccountBinding $binding, Operation $op): ?string
    {
        if($op->action!=='register'||$op->registrar_key!=='enom'||!self::enabled($binding)){return 'enom_test_setup_required';}
        $protocol=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
        if(!$protocol['batch_route']||$protocol['interval_seconds']<25||!self::protocolEvidence($binding,$protocol,false)){return 'write_acceptance_required';}
        try {
            $accepted=CatalogueAcceptances::load($binding);$domain=\Paymenter\Extensions\Servers\Domains\Models\Domain::findOrFail($op->domain_id);
            $parts=\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse($domain->domain,array_keys($accepted->endings));
            $row=$accepted->endings[$parts['tld']]??null;
            if(!$row||!in_array((int)$op->term,$row['register'],true)||$row['renew']!==[1]||!$row['renewal_safe']||!$row['registry']){return 'write_acceptance_required';}
        } catch(\Throwable){return 'write_acceptance_required';}
        return self::balanceEvidence($binding,false)?null:'usd_balance_required';
    }
    /** Pure: no credentials, queries, I/O, account mutation or feature inference from processing. */
    public static function factory(AccountBinding $binding): \Closure
    {
        if (!self::enabled($binding)) { throw new \DomainException(self::reason()); }
        // USD comes only from the existing audited manual/preflight balance reads, bound to this fingerprint.
        // Route and spacing come from A6's actual TEST endpoint/command and worker request policy.
        $protocolAcceptance=\Paymenter\Extensions\Servers\Domains\Registrars\Enom\RuntimeFactory::registrationAcceptance($binding);
        if(!$protocolAcceptance['batch_route']||$protocolAcceptance['interval_seconds']<25){throw new \DomainException('TEST registration acceptance evidence is unavailable.');}
        $usdProbe=static fn(AccountBinding $current,bool $lock):bool=>$binding->matches($current)&&self::balanceEvidence($current,$lock);
        $protocolProbe=static fn(AccountBinding $current,bool $lock):bool=>$binding->matches($current)&&self::protocolEvidence($current,$protocolAcceptance,$lock);
        return app(AccountBindings::class)->enomRuntimeFactory($binding,app(Clock::class),new WriteFeatures(registration:true),
            new WriteAcceptance($binding,true,$protocolAcceptance['batch_route'],$protocolAcceptance['interval_seconds'],null,$usdProbe,$protocolProbe),
            new RenewalAcceptance($binding),new TransferPrecheck(new Rdap));
    }
    private static function protocolEvidence(AccountBinding $binding,array $protocol,bool $lock): bool
    {
        if($binding->registrarKey!=='enom'||$binding->environment!==\Paymenter\Extensions\Servers\Domains\Data\Environment::Test){return false;}
        try {
            $accepted=CatalogueAcceptances::load($binding,$lock);
            return $protocol['batch_route']===true&&$accepted->registrationRoute===$protocol['route']
                &&$accepted->registrationIntervalSeconds===$protocol['interval_seconds'];
        } catch(\Throwable){return false;}
    }
    private static function balanceEvidence(AccountBinding $binding,bool $lock): bool
    {
        if($binding->registrarKey!=='enom'||$binding->environment!==\Paymenter\Extensions\Servers\Domains\Data\Environment::Test){return false;}
        try {
            $q=DB::table(BalanceSchema::TABLE)->where('server_id',$binding->serverId)->where('account_fingerprint',$binding->fingerprint);
            if($lock){$q->lockForUpdate();}$row=$q->first();$now=app(Clock::class)->now()->getTimestamp();
            return $row!==null&&in_array($row->source,['manual','preflight'],true)&&$row->last_error_code===null&&$row->currency==='USD'&&$row->amount!==null
                &&$row->read_at!==null&&(int)$row->read_at<=$now&&(int)$row->read_at>=$now-3600&&(int)$row->attempted_at===(int)$row->read_at;
        } catch(\Throwable){return false;}
    }
}
