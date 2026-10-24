<?php
// Transport adapted from Enom 444f31c RegistrarClient; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ManagementCommand,ReadCommand,RegistrationCommand};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,EnomTestCallLimit,OperationClaims,RenewalAcceptance,Runtime,RuntimeComposition,TransferPrecheck,WriteAcceptance,WriteFeatures};

/** Deployment opt-in only. Exchanges remain private inside the genuine engine-bound protocols. */
final class RuntimeFactory
{
    private readonly \SensitiveParameterValue $credentials;
    private function __construct(private readonly AccountBinding $binding, #[\SensitiveParameter] \Closure $credentials)
    { $this->credentials=new \SensitiveParameterValue($credentials); }

    /** Pure per-fingerprint factory: neither this call nor invoking its result decrypts or performs I/O. */
    public static function make(AccountBinding $binding, AccountBindings $bindings, Clock $clock, WriteFeatures $features,
        WriteAcceptance $accepted, RenewalAcceptance $renewalAccepted, TransferPrecheck $precheck,
        #[\SensitiveParameter] \Closure $credentials, bool $managementAccepted=false): \Closure
    {
        if ($binding->registrarKey!=='enom') { throw new \InvalidArgumentException('Enom runtime binding required.'); }
        $wire=new self($binding,$credentials);
        return static fn()=>RuntimeComposition::make($binding,$bindings,$clock,$features,$accepted,$renewalAccepted,$precheck,
            new Registration($binding,$wire->exchange('registration')),new Transfer($binding,$wire->exchange('transfer')),
            $wire->exchange('renewal'),new Management($binding,$wire->exchange('management')),$managementAccepted);
    }

    /** Reuse the reviewed TEST Purchase route and worker request interval as acceptance evidence. */
    public static function registrationAcceptance(AccountBinding $binding): array
    {
        $test=$binding->registrarKey==='enom'&&$binding->environment===Environment::Test;
        $route=self::originFor(Environment::Test);
        return [
            'route'=>$route,
            'batch_route'=>$test&&$route==='https://resellertest.enom.com/interface.asp'
                &&RegistrationCommand::Purchase->value==='Purchase',
            'interval_seconds'=>\Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation::REQUEST_SECONDS,
        ];
    }

    private function exchange(string $role): \Closure
    {
        return function(string $origin,string $command,#[\SensitiveParameter] array $parameters,int $timeoutMs)use($role):string {
            $handle=null;$entered=hrtime(true);$deadline=$entered+max(0,$timeoutMs)*1000000;
            try {
                OperationClaims::outside();
                if (Runtime::limiter()===null || $origin!==$this->origin() || $timeoutMs<1 || $timeoutMs>25000
                    || (getenv('SSLKEYLOGFILE')!==false && getenv('SSLKEYLOGFILE')!=='')) {
                    throw new \DomainException('Enom exchange refused.');
                }
                $named=$this->command($role,$command);
                self::parameters($parameters);
                // Called only after the protocol's live grant / physical limiter / deadline checks.
                $credentials=($this->credentials->getValue())(!($named instanceof ReadCommand));
                $uid=$credentials['uid']??null;$token=$credentials['token']??null;
                if (!is_string($uid)||!is_string($token)||$uid===''||$token===''||strlen($token)>4096||preg_match('/[\x00-\x1f\x7f]/',$token)) {
                    throw new \DomainException('Enom credentials unavailable.');
                }
                $this->binding->requireMatch(new AccountBinding('enom',$this->binding->serverId,$this->binding->environment,$uid));
                $timeoutMs=intdiv($deadline-hrtime(true),1000000);
                if ($timeoutMs<1) {
                    if($this->binding->environment===Environment::Test){throw new EnomTestCallLimit;}
                    throw new \RuntimeException('Enom exchange deadline reached.');
                }
                $body='';$handle=curl_init();
                if ($handle===false) { throw new \RuntimeException('Enom exchange failed.'); }
                $options=[
                    CURLOPT_URL=>$this->origin(),CURLOPT_POST=>true,
                    CURLOPT_POSTFIELDS=>http_build_query(['Command'=>$named->value,'UID'=>$uid,'PW'=>$token,'ResponseType'=>'XML']+$parameters,'','&',PHP_QUERY_RFC3986),
                    CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/xml'],
                    CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',
                    CURLOPT_VERBOSE=>false,CURLOPT_HEADER=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                    CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT_MS=>min(8000,$timeoutMs),CURLOPT_TIMEOUT_MS=>$timeoutMs,
                    CURLOPT_LOW_SPEED_LIMIT=>32,CURLOPT_LOW_SPEED_TIME=>10,CURLOPT_FRESH_CONNECT=>true,CURLOPT_FORBID_REUSE=>true,
                    CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body,$named):int {
                        if (strlen($chunk)>$named->bodyLimit()-strlen($body)) { return 0; }
                        $body.=$chunk;return strlen($chunk);
                    },
                ];
                if(!curl_setopt_array($handle,$options)){throw new \RuntimeException('Enom exchange incomplete.');}
                $purchase=$named===RegistrationCommand::Purchase;
                $day=\Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::prepareSend($this->binding,$purchase);
                $timeoutMs=intdiv($deadline-hrtime(true),1000000);
                if($timeoutMs<1){
                    if($this->binding->environment===Environment::Test){throw new EnomTestCallLimit;}
                    throw new \RuntimeException('Enom exchange deadline reached.');
                }
                $options[CURLOPT_CONNECTTIMEOUT_MS]=min(8000,$timeoutMs);$options[CURLOPT_TIMEOUT_MS]=$timeoutMs;
                if(!curl_setopt_array($handle,$options)){throw new \RuntimeException('Enom exchange incomplete.');}
                \Paymenter\Extensions\Servers\Domains\Services\EnomTestCalls::authorizeSend($this->binding,$purchase,$day);
                if (curl_exec($handle)===false||curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200) {
                    throw new \RuntimeException('Enom exchange incomplete.');
                }
                return $body;
            } catch (EnomTestCallLimit) {
                throw new EnomTestCallLimit;
            } catch (\Throwable) {
                // Discard cURL/raw-response/credential-bearing exceptions, including their causes.
                throw new \RuntimeException('Enom exchange failed.');
            } finally {
                if ($handle!==null&&$handle!==false) { curl_close($handle); }
            }
        };
    }

    private function origin(): string { return self::originFor($this->binding->environment); }

    private static function originFor(Environment $environment): string
    { return $environment===Environment::Live?'https://reseller.enom.com/interface.asp':'https://resellertest.enom.com/interface.asp'; }

    private function command(string $role,string $command): RegistrationCommand|ManagementCommand|ReadCommand
    {
        $allowed=match($role) {
            'registration'=>[RegistrationCommand::Purchase,RegistrationCommand::Nameservers,RegistrationCommand::NameserverReadback],
            'transfer'=>[RegistrationCommand::Transfer],
            'renewal'=>[RegistrationCommand::Renew,RegistrationCommand::GraceRenew],
            'management'=>[...ManagementCommand::cases(),ReadCommand::Info,ReadCommand::Nameservers,ReadCommand::Contacts,
                ReadCommand::Lock,ReadCommand::Privacy,ReadCommand::Renewal,ReadCommand::Hosts,ReadCommand::Srv,ReadCommand::Ds,
                ReadCommand::HostStatus,ReadCommand::Balance,ReadCommand::Order],
            default=>[],
        };
        foreach ($allowed as $named) {
            if ($named->value===$command) { return $named; }
        }
        throw new \DomainException('Unsupported Enom protocol command.');
    }

    private static function parameters(#[\SensitiveParameter] array $parameters): void
    {
        if (count($parameters)>512) { throw new \InvalidArgumentException('Enom request too large.'); }
        $seen=[];
        foreach ($parameters as $key=>$value) {
            $lower=is_string($key)?strtolower($key):'';
            if (!preg_match('/^[a-z][a-z0-9_]*$/D',$lower)||isset($seen[$lower])
                ||in_array($lower,['uid','pw','command','responsetype','overrideorder','usewiretransfer','deleteorderdetail',
                    'creditcardnumber','creditcardexpmonth','creditcardexpyear','cvv2','ccname','chargeamount','premiumdomain','customersuppliedprice'],true)
                ||(!is_string($value)&&!is_int($value)&&!is_bool($value))||strlen((string)$value)>4096) {
                throw new \InvalidArgumentException('Invalid Enom protocol parameter.');
            }
            $seen[$lower]=true;
            if (($lower==='usecreditcard'&&$value!=='no')
                ||(in_array($lower,['renewname','renewflag','renew','wppsrenew','autopakrenew','emailforwardrenew','urlforwardrenew','emailnotify'],true)
                    &&!in_array(strtolower((string)$value),['0','false','no',''],true))
                ||($lower==='emailepp'&&($value!=='True'))) {
                throw new \InvalidArgumentException('Unsafe Enom parameter.');
            }
        }
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Enom runtime factories cannot be persisted.'); }
    public function __clone(): void { throw new \LogicException('Enom runtime factories cannot be cloned.'); }
}
