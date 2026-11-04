<?php
// Transport options adapted from NameSilo Api/CurlTransport; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{CurlTransport,DeadlineTransport,Environment,RegistrarRequest,RequestContext,TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{LiveReservationLimit,LiveReservations,OperationClaims,ProviderSendGuard};
/** Account-bound port. The installed binding factory supplies fresh, verified credentials on every call. */
final class AdmittedTransport implements DeadlineTransport
{
    private readonly \SensitiveParameterValue $credentials;
    private readonly NameSiloLiveReservationPolicy|TestReservationPolicy $policy;
    public readonly SendReservation $reservation;
    public function __construct(private readonly AccountBinding $binding,#[\SensitiveParameter] \Closure $credentials)
    {
        if($binding->registrarKey!=='namesilo'){throw new \InvalidArgumentException('NameSilo transport required.');}
        $this->credentials=new \SensitiveParameterValue($credentials);$this->policy=$binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?new NameSiloLiveReservationPolicy:new TestReservationPolicy;
        $this->reservation=new SendReservation($binding,$this->policy);
    }
    public function send(Environment $environment,RequestContext $context,RegistrarRequest $request,#[\SensitiveParameter] string $apiKey): TransportResponse
    {return $this->sendWithin($environment,$context,$request,$apiKey,25000);}
    public function sendWithin(Environment $environment,RequestContext $context,RegistrarRequest $request,#[\SensitiveParameter] string $apiKey,int $timeoutMs): TransportResponse
    {
        if($environment!==$this->environment()){throw new LiveReservationLimit;}
        return $this->wire($environment->origin().'/'.$context->value,$request->command,$request->parameters(),$timeoutMs,$apiKey);
    }
    /** Inject only into genuine engine-bound protocols. Writes additionally need the pre-seal reservation scope. */
    public function exchange(): \Closure
    {return fn(string $origin,string $command,#[\SensitiveParameter] array $parameters,int $timeoutMs):string=>$this->wire($origin,$command,$parameters,$timeoutMs)->body();}
    public static function feature(string $command): string
    {
        return match($command){
            'getAccountBalance','getPrices','checkRegisterAvailability','checkTransferAvailability','getDomainInfo','checkTransferStatus','listOrders','orderDetails','contactList','listRegisteredNameServers','dnsListRecords'=>'catalogue',
            'registerDomain'=>'registration','transferDomain'=>'transfer','renewDomain'=>'renewal',
            'contactAdd','contactDomainAssociate','changeNameServers','domainLock','domainUnlock','addPrivacy','removePrivacy','removeAutoRenewal','retrieveAuthCode',
            'addRegisteredNameServer','modifyRegisteredNameServer','deleteRegisteredNameServer','dnsAddRecord','dnsUpdateRecord','dnsDeleteRecord'=>'management',
            default=>throw new LiveReservationLimit,
        };
    }
    /** Refuse credential overrides, external funding and implicit auto-renew before reserving or creating a handle. */
    public static function parameters(string $command,#[\SensitiveParameter] array $parameters): void
    {
        self::feature($command);
        if(count($parameters)>64){throw new LiveReservationLimit;}
        foreach($parameters as $key=>$value){
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]*$/D',$key)
                ||in_array($key,['key','version','type','payment_id','coupon','portfolio','retail_prices'],true)
                ||(!is_string($value)&&!is_int($value))||strlen((string)$value)>4096){throw new LiveReservationLimit;}
        }
        if(array_key_exists('auto_renew',$parameters)&&$parameters['auto_renew']!==0){throw new LiveReservationLimit;}
        if(in_array($command,['registerDomain','transferDomain','renewDomain'],true)){
            if(($parameters['auto_renew']??null)!==0||!is_string($parameters['domain']??null)
                ||\Paymenter\Extensions\Servers\Domains\Data\CanonicalName::normalize($parameters['domain'])!==$parameters['domain']){throw new LiveReservationLimit;}
            if($command!=='transferDomain'&&($parameters['years']??null)!==1){throw new LiveReservationLimit;}
            if($command==='renewDomain'&&array_diff(array_keys($parameters),['domain','years','auto_renew'])){throw new LiveReservationLimit;}
            if($command!=='renewDomain'&&!in_array($parameters['private']??null,[0,1],true)){throw new LiveReservationLimit;}
            if($command==='transferDomain'&&array_key_exists('years',$parameters)){throw new LiveReservationLimit;}
        }
    }
    private function wire(string $origin,string $command,#[\SensitiveParameter] array $parameters,int $timeoutMs,#[\SensitiveParameter] ?string $expectedKey=null): TransportResponse
    {
        $handle=null;$deadline=hrtime(true)+max(0,$timeoutMs)*1000000;
        try {
            OperationClaims::outside();$feature=self::feature($command);$write=$feature!=='catalogue';
            if($timeoutMs<1||$timeoutMs>25000||getenv('SSLKEYLOGFILE')||!function_exists('curl_init')
                ||!in_array($origin,[$this->environment()->origin().'/api',$this->environment()->origin().'/apibatch'],true)
                ||$write&&$origin!==$this->environment()->origin().'/apibatch'){throw new LiveReservationLimit;}
            self::parameters($command,$parameters);$this->policy->requireFeature($this->binding,$feature);
            // The factory must revalidate AccountBindings' encrypted credential digest and processing/site binding here.
            $credentials=($this->credentials->getValue())($write);$key=$credentials['api_key']??null;
            if(!is_string($key)||$key===''||strlen($key)>256||preg_match('/[\x00-\x20\x7f]/',$key)
                ||$expectedKey!==null&&!hash_equals($key,$expectedKey)){throw new LiveReservationLimit;}
            $body='';$handle=curl_init();if($handle===false){throw new \RuntimeException;}
            $options=[CURLOPT_URL=>$origin.'/'.$command.'?'.http_build_query(['version'=>1,'type'=>'json','key'=>$key]+$parameters,'','&',PHP_QUERY_RFC3986),
                CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_VERBOSE=>false,CURLOPT_HEADER=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS=>min(8000,$timeoutMs),CURLOPT_TIMEOUT_MS=>$timeoutMs,
                CURLOPT_LOW_SPEED_LIMIT=>32,CURLOPT_LOW_SPEED_TIME=>10,CURLOPT_FRESH_CONNECT=>true,CURLOPT_FORBID_REUSE=>true,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int {
                    if(strlen($chunk)>CurlTransport::BODY_LIMIT-strlen($body)){return 0;}$body.=$chunk;return strlen($chunk);
                }];
            if(!curl_setopt_array($handle,$options)){throw new \RuntimeException;}
            $day=$write?null:(new LiveReservations($this->policy,$this->binding->environment))->reserve($this->binding);
            ProviderSendGuard::beforeSend('namesilo',$this->binding->environment->value,$command);
            $this->policy->requireFeature($this->binding,$feature);
            $now=app(Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'));
            $midnight=$now->modify('+1 day')->setTime(0,0);
            $dayMs=intdiv(($midnight->getTimestamp()-$now->getTimestamp())*1000000-(int)$now->format('u'),1000);
            $remaining=min($dayMs,intdiv($deadline-hrtime(true),1000000));if($remaining<1){throw new LiveReservationLimit;}
            if(!curl_setopt_array($handle,[CURLOPT_CONNECTTIMEOUT_MS=>min(8000,$remaining),CURLOPT_TIMEOUT_MS=>$remaining])){throw new \RuntimeException;}
            if($write){$this->reservation->consume($command,$parameters);}elseif($day!==LiveReservations::day()){throw new LiveReservationLimit;}
            if(hrtime(true)>=$deadline){throw new LiveReservationLimit;}
            if(curl_exec($handle)===false||curl_getinfo($handle,CURLINFO_RESPONSE_CODE)!==200){throw new \RuntimeException;}
            return new TransportResponse(200,$body);
        }catch(LiveReservationLimit){throw new LiveReservationLimit;}
        catch(\Throwable){throw new \RuntimeException('NameSilo transport outcome unresolved.');}
        finally{if($handle!==null&&$handle!==false){curl_close($handle);}}
    }
    private function environment(): Environment
    {return $this->binding->environment===\Paymenter\Extensions\Servers\Domains\Data\Environment::Live?Environment::Live:Environment::Sandbox;}
    public function __debugInfo(): array {return ['registrar'=>'namesilo','environment'=>$this->binding->environment->value];}
    public function __serialize(): never {throw new \LogicException('Admitted transports cannot be persisted.');}
    public function __clone(): void {throw new \LogicException('Admitted transports cannot be cloned.');}
}
