<?php
// Enom 444f31c named snapshot reads; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Contracts\ObservationReader as ReadPort;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,ManagementRequest,RequestContext};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand as R,ResponseParser,ProtocolResult};
use Paymenter\Extensions\Servers\Domains\Services\{OperationClaims,ReadDeferred,EnomTestCallLimit,ReadFailure};
/** Dormant read-only composition over the accepted identity/record discovery contract. */
final class ObservationReader implements ReadPort
{
    private readonly Management $management;
    private readonly \SensitiveParameterValue $exchange;
    public function __construct(private readonly AccountBinding $binding, #[\SensitiveParameter] \Closure $exchange)
    {
        if ($binding->registrarKey!=='enom') { throw new \InvalidArgumentException('Wrong observation registrar.'); }
        $this->management=new Management($binding,$exchange);$this->exchange=new \SensitiveParameterValue($exchange);
    }
    public function bindsTo(AccountBinding $binding): bool { return $this->binding->matches($binding); }
    public function observationCapabilities(): array { return $this->management->observationCapabilities(); }
    public function discover(ManagementRequest $r,RequestContext $c,array $knownGlue=[]): array
    {
        $out=$this->management->discover($r,$c,$knownGlue);
        $out['settings']=array_fill_keys(['nameservers','contacts','flags'],['state'=>'unknown','facts'=>[]]);
        if (isset($out['failure_code'])) {
            foreach (['dns','srv','ds'] as $kind) { if (($out[$kind]['state']??null)!=='complete') { $out['failed_family']=$kind;break; } }
            $out['failed_family']??='glue';return $out;
        }
        $family='nameservers';
        try {
            $out['settings']['nameservers']=['state'=>'complete','facts'=>['nameservers'=>ManagementRead::nameservers($this->get($r,$c,R::Nameservers))]];
            $family='contacts';$contacts=Contacts::read($this->get($r,$c,R::Contacts));
            if ($contacts===null) { throw new \DomainException('Contacts unavailable.'); }
            $complete=true;
            foreach (['registrant','admin','tech','auxbilling'] as $role) {
                if (($contacts['collected'][$role]??null)===false) { if ($role==='registrant') { $complete=false; } continue; }
                foreach (['first_name','last_name','address1','city','postal_code','country','email_address','phone'] as $field) {
                    if (!is_string($contacts['contacts'][$role][$field]??null)||trim($contacts['contacts'][$role][$field])==='') { $complete=false; }
                }
            }
            $out['settings']['contacts']=['state'=>$complete?'complete':'partial','facts'=>$contacts];
            if (!$complete) { throw new \DomainException('Contacts partial.'); }
            $family='flags';
            $locked=Driver::flag($this->get($r,$c,R::Lock)->value('reg-lock'));
            $renew=$this->get($r,$c,R::Renewal);$auto=Driver::flag($renew->value('auto-renew',$renew->value('renewname')));
            $privacy=ManagementRead::privacy($this->get($r,$c,R::Privacy));
            $facts=['locked'=>$locked,'auto_renew'=>$auto,'privacy'=>$privacy['enabled']];
            $out['settings']['flags']=['state'=>in_array(null,$facts,true)?'partial':'complete','facts'=>$facts];
        } catch (ReadDeferred|EnomTestCallLimit $e) { throw $e; }
        catch (\Throwable $e) { $out['failure_code']=ReadFailure::code($e);$out['failed_family']=$family; }
        return $out;
    }
    private function get(ManagementRequest $r,RequestContext $c,R $command): ProtocolResult
    {
        $parts=explode('.',$r->name,2);$p=['SLD'=>$parts[0],'TLD'=>$parts[1]];$command->validate($p);
        $origin=$this->binding->environment->value==='live'?'https://reseller.enom.com/interface.asp':'https://resellertest.enom.com/interface.asp';
        OperationClaims::outside();$this->binding->requireMatch($r->binding);$this->binding->requireMatch($c->binding);
        try { $body=$c->withinRead($this->binding,fn($remaining)=>($this->exchange->getValue())($origin,$command->value,$p,min(25000,$remaining))); }
        catch (ReadDeferred|EnomTestCallLimit $e) { throw $e; }
        catch (\Throwable) { throw new \RuntimeException('Observation transport unavailable.'); }
        if (!is_string($body)||strlen($body)>1048576) { throw new \DomainException('Observation response unavailable.'); }
        $v=(new ResponseParser)->parse($command,$body);
        $c->requireReadSuccess($v->status==='success');
        if ($v->status!=='success') { throw new \DomainException('Observation read failed.'); }return $v;
    }
    public function __debugInfo(): array { return []; }
    public function __serialize(): never { throw new \LogicException('Observation ports are server-only.'); }
}
