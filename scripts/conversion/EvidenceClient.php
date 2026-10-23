<?php
// Transport options adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use Paymenter\Extensions\Servers\Domains\Data\CanonicalName;
/** Closed development client. The optional exchange/clock seams are for offline fixtures only. */
final class EvidenceClient
{
    public const LIMIT=1048576;
    private array $starts=[];
    public function __construct(private ?\Closure $exchange=null,private ?\Closure $clock=null,private ?\Closure $sleep=null) {}
    public function read(string $command,int $account,string $environment,#[\SensitiveParameter] string $uid,
        #[\SensitiveParameter] string $token,array $domain): string
    {
        if($command!=='GetDomainInfo'){throw new \LogicException('Collector command refused.');}
        if(!in_array($environment,['test','live'],true)||$account<1||$uid===''||strlen($uid)>4096||$token===''||strlen($token)>4096
            ||preg_match('/[\x00-\x1f\x7f]/',$uid.$token)||(getenv('SSLKEYLOGFILE')!==false&&getenv('SSLKEYLOGFILE')!=='')){throw new \RuntimeException('Collector read refused.');}
        $parsed=CanonicalName::parse($domain['domain'],[$domain['tld']]);
        if($parsed['domain']!==$domain['domain']||$parsed['sld']!==$domain['sld']){throw new \LogicException('Collector name refused.');}
        $clock=$this->clock??static fn()=>hrtime(true)/1e9;$sleep=$this->sleep??static fn(float $seconds)=>usleep((int)ceil($seconds*1e6));
        // Duplicate native servers for the same provider login share one rate budget.
        $key=hash('sha256',$environment.':'.strtolower($uid));
        // Failed requests consume spacing too. Monotonic starts, never keyed by name.
        while(($wait=($this->starts[$key]??-INF)+1-$clock())>0){$sleep($wait);}
        $this->starts[$key]=$clock();$handle=null;$body='';
        try {
            $options=[CURLOPT_URL=>$environment==='live'?'https://reseller.enom.com/interface.asp':'https://resellertest.enom.com/interface.asp',
                CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['Command'=>$command,'UID'=>$uid,'PW'=>$token,'ResponseType'=>'XML','SLD'=>$parsed['sld'],'TLD'=>$parsed['tld']],'','&',PHP_QUERY_RFC3986),
                CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/xml'],
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_VERBOSE=>false,CURLOPT_HEADER=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT_MS=>10000,CURLOPT_TIMEOUT_MS=>30000,CURLOPT_FRESH_CONNECT=>true,CURLOPT_FORBID_REUSE=>true,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($chunk)>self::LIMIT-strlen($body)){return 0;}$body.=$chunk;return strlen($chunk);}];
            if($this->exchange){[$status,$reply]=($this->exchange)($options);}
            else {
                $handle=curl_init();if($handle===false||!curl_setopt_array($handle,$options)||curl_exec($handle)===false){throw new \RuntimeException;}
                $status=curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$reply=$body;
            }
            if($status!==200||!is_string($reply)||strlen($reply)>self::LIMIT){throw new \RuntimeException;}
            return $reply;
        } catch(\Throwable){throw new \RuntimeException('Collector read incomplete.');}
        finally {if($handle!==null&&$handle!==false){curl_close($handle);}}
    }
    public function __debugInfo(): array {return ['read_only'=>true];}
    public function __serialize(): array {throw new \LogicException('Collector client cannot be serialized.');}
    public function __clone(): void {throw new \LogicException('Collector client cannot be cloned.');}
}
