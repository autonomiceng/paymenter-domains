<?php
// Adapted from Enom 444f31c management/paid privacy; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Contracts\ManagementProtocol;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, Capabilities, Capability, ManagementRequest, ManagementResult, PrivacyMode, RequestContext, Support};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ManagementCommand as M, ProtocolResult, ReadCommand as R, ResponseParser};
final class Management extends ManagementProtocol
{
    public function capabilities(): Capabilities
    { return new Capabilities($this->binding, array_fill_keys(['nameservers','contacts','lock','privacy','dns','ds','glue','auto_renew_off','auth_code_email'],new Capability(Support::Supported))+['auth_code_display'=>new Capability(Support::Unsupported)],PrivacyMode::Paid); }
    public function validate(string $action, #[\SensitiveParameter] array $payload, string $name): array { return ManagementInput::validate($action,$payload,$name); }
    protected function origin(RequestContext $context): string { return $this->binding->environment->value === 'live' ? 'https://reseller.enom.com/interface.asp' : 'https://resellertest.enom.com/interface.asp'; }
    protected function readCommands(): array { return array_map(fn($c)=>$c->value,[R::Info,R::Nameservers,R::Contacts,R::Lock,R::Privacy,R::Renewal,R::Hosts,R::Srv,R::Ds,R::HostStatus,R::Balance,R::Order]); }
    protected function validateRead(string $command, #[\SensitiveParameter] array $parameters): void { R::from($command)->validate($parameters); }
    protected function writeCommand(string $action): string
    {
        return (match($action){'nameservers'=>M::Nameservers,'contacts'=>M::Contacts,'lock'=>M::Lock,'auth_code_email'=>M::Email,'auto_renew_off'=>M::AutoRenew,
            'privacy_enable'=>M::Enable,'privacy_disable'=>M::Disable,'privacy_purchase'=>M::Purchase,'privacy_renew'=>M::Renew,'dns'=>M::Dns,'srv'=>M::Srv,
            'ds_add'=>M::DsAdd,'ds_delete'=>M::DsDelete,'glue_add'=>M::GlueAdd,'glue_update'=>M::GlueUpdate,'glue_delete'=>M::GlueDelete,default=>throw new \DomainException('Unsupported action.')})->value;
    }
    private function parts(ManagementRequest $r): array
    { $p=CanonicalName::parse($r->name,[substr($r->name,strpos($r->name,'.')+1)]); return ['SLD'=>$p['sld'],'TLD'=>$p['tld']]; }
    private function get(ManagementRequest $r, RequestContext $c, R $cmd, ?array $p=null): ProtocolResult
    {
        $remaining=$c->budget->remaining();
        try { $reply=(new ResponseParser)->parse($cmd,$this->read($r,$c,$cmd->value,$p??$this->parts($r))); }
        catch (\Throwable $e) {
            if($cmd===R::Balance&&$c->budget->remaining()<$remaining&&!$e instanceof \Paymenter\Extensions\Servers\Domains\Services\ReadDeferred&&!$e instanceof \Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit){\Paymenter\Extensions\Servers\Domains\Services\BalanceReads::record($this->binding,null,$c->now(),'preflight','read_failed');}
            throw $e;
        }
        if($cmd===R::Balance){
            $money=Balance::read($reply);
            if($money||$c->budget->remaining()<$remaining){\Paymenter\Extensions\Servers\Domains\Services\BalanceReads::record($this->binding,$money,$c->now(),'preflight',$money?null:'read_failed');}
        }
        return $reply;
    }
    private function owned(ManagementRequest $r, RequestContext $c): void
    {
        $v=$this->get($r,$c,R::Info); $d=$v->value('getdomaininfo.domainname'); $s=$v->value('getdomaininfo.status');
        $name=is_array($d)?($d['#text']??null):$d; $attr=is_array($d)?($d['@attributes']??[]):[]; $parts=$this->parts($r);
        $alias=$v->value('getdomaininfo.domainnameid'); $id=$attr['domainnameid']??$alias;
        if(!is_array($attr)||(isset($attr['sld'])&&strtolower($attr['sld'])!==$parts['SLD'])||(isset($attr['tld'])&&strtolower($attr['tld'])!==$parts['TLD'])
            ||(isset($attr['domainnameid'],$alias)&&$attr['domainnameid']!==$alias)||!is_string($id)||!preg_match('/^[1-9][0-9]{0,19}$/D',$id)){throw new \DomainException('Domain identity unknown.');}
        $owner=is_array($s)?($s['belongs-to']??null):null; if(is_array($owner)){$owner=$owner['#text']??null;}
        if($v->status!=='success'||!is_string($name)||strtolower($name)!==$r->name||!is_string($owner)||$id!==$r->providerId
            || !$this->binding->matches(new AccountBinding('enom',$this->binding->serverId,$this->binding->environment,$owner))) { throw new \DomainException('Owned domain identity required.'); }
    }
    /** Frozen Enom 444f31c snapshot/currentHostIp paths, through the existing bounded read seam. */
    public function discover(ManagementRequest $r, RequestContext $c, array $knownGlue = []): array
    {
        $out=parent::discover($r,$c); $this->owned($r,$c);
        $out['glue']['records']=array_slice($knownGlue,0,50,true);
        foreach (['dns'=>R::Hosts,'srv'=>R::Srv,'ds'=>R::Ds] as $kind=>$command) {
            try {
                $v=$this->get($r,$c,$command); $c->requireReadSuccess($v->status==='success');
                $out[$kind]=['state'=>'complete','records'=>ManagementRead::records($v,$kind)];
            } catch (\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred|\Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit $e) { throw $e; }
            catch (\Throwable $error) { $out['failure_code']=\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::code($error); return $out; } // Stop at first failure; never retry a failed wire.
        }
        foreach (array_slice($knownGlue,0,13,true) as $host=>$prior) {
            try {
                $ip=ManagementRead::glue($this->get($r,$c,R::HostStatus,['CheckNSName'=>$host]),$host);
                if ($ip!==null) { \Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::publicIp($ip); $out['glue']['records'][$host]=$ip; } else { unset($out['glue']['records'][$host]); }
            } catch (\Paymenter\Extensions\Servers\Domains\Services\ReadDeferred|\Paymenter\Extensions\Servers\Domains\Services\EnomTestCallLimit $e) { throw $e; }
            catch (\Throwable $error) { $out['failure_code']=\Paymenter\Extensions\Servers\Domains\Services\ReadFailure::code($error); $out['glue']['read_failed']=true; break; } // Retain partial history, never infer absence.
        }
        $hosts=$out['glue']['records'];
        if (count($hosts)>13) { $out['glue']['records']=array_slice($hosts,13,null,true)+array_slice($hosts,0,13,true); }
        return $out;
    }
    public function prepare(ManagementRequest $r, RequestContext $c): array { return $this->preflight($r,$c,false); }
    public function billingTerms(ManagementRequest $r, RequestContext $c): array { return $this->preflight($r,$c,true); }
    private function preflight(ManagementRequest $r, RequestContext $c, bool $billing): array
    {
        $this->owned($r,$c); $p=$r->payload(); $a=$r->action;
        if(in_array($a,['dns','srv'],true)) {
            $v=$this->get($r,$c,$a==='dns'?R::Hosts:R::Srv); if($v->status!=='success'||ManagementInput::digest(ManagementRead::records($v,$a),$a)!==$p['preimage_digest']){throw new \DomainException('DNS preimage changed.');}
        }
        if(str_starts_with($a,'glue_')) {
            $old=ManagementRead::glue($this->get($r,$c,R::HostStatus,['CheckNSName'=>$p['host']]),$p['host']);
            if(($a==='glue_add'&&$old!==null)||($a!=='glue_add'&&$old===null)||($a==='glue_update'&&inet_pton($old)===inet_pton($p['ip']))){throw new \DomainException('Glue precondition changed.');}
            if($a==='glue_update'){$p['old_ip']=$old;}
        }
        if(str_starts_with($a,'privacy_')) {
            $v=$this->get($r,$c,R::Privacy); if($v->status!=='success'){throw new \DomainException('Privacy unknown.');} $s=ManagementRead::privacy($v);
            if($s['allowed']!==true||$s['exists']===null||($a==='privacy_purchase'?$s['exists']!==false:$s['exists']!==true)){throw new \DomainException('Privacy precondition unavailable.');}
            if(in_array($a,['privacy_purchase','privacy_renew'],true)) {
                if((!$billing&&$s['cost']===null)||($a==='privacy_renew'&&(!$s['expiry']||isset($p['privacy_terms'])&&$p['privacy_terms']['base']!==$s['expiry']))){throw new \DomainException('Privacy cost or expiry unknown.');}
                if(!$billing){$balance=Balance::read($this->get($r,$c,R::Balance,[]));
                    if($balance===null||\Paymenter\Extensions\Servers\Domains\Services\WriteMoney::compare($balance->amount,$s['cost'])<0){throw new \DomainException('Privacy balance unknown or insufficient.');}}
                $base=$a==='privacy_renew'?$s['expiry']:$c->now()->format('Y-m-d');
                $p['privacy_terms']=['base'=>$a==='privacy_renew'?$base:null,'expected'=>\Carbon\CarbonImmutable::parse($base)->addYearNoOverflow()->format('Y-m-d'),'cost'=>$s['cost']];
            }
        }
        return $p;
    }
    protected function apply(ManagementRequest $r, RequestContext $c): ManagementResult
    {
        $p=$r->payload(); $a=$r->action; $args=$this->parts($r); $extra=[];
        $cmd=M::from($this->writeCommand($a));
        if($a==='nameservers'){if(($p['use_default']??false)===true){$extra=['UseDNS'=>'default'];}else{foreach($p['nameservers'] as $i=>$v){$extra['NS'.($i+1)]=$v;}}}
        elseif($a==='lock'){$extra=['UnlockRegistrar'=>$p['locked']?0:1];}
        elseif($a==='auth_code_email'){$extra=['EmailEPP'=>'True','RunSynchAutoInfo'=>'True'];}
        elseif($a==='auto_renew_off'){$extra=['RenewFlag'=>0,'WPPSRenew'=>0];}
        elseif(str_starts_with($a,'privacy_')){$extra=['Service'=>'WPPS']; if(in_array($a,['privacy_purchase','privacy_renew'],true)){$extra+=['NumYears'=>1,'UseCreditCard'=>'no'];if($a==='privacy_purchase'){$extra+=['RenewName'=>0,'EmailNotify'=>0];}}}
        elseif($a==='contacts'){
            $map=['first_name'=>'FirstName','last_name'=>'LastName','organization_name'=>'OrganizationName','job_title'=>'JobTitle','address1'=>'Address1','address2'=>'Address2','city'=>'City','state_province'=>'StateProvince','postal_code'=>'PostalCode','country'=>'Country','email_address'=>'EmailAddress','phone'=>'Phone','phone_ext'=>'PhoneExt','fax'=>'Fax'];
            foreach($p['role']==='all'?['registrant','admin','tech','auxbilling']:[$p['role']] as $role){foreach($p['contact'] as $k=>$v){$extra[($role==='auxbilling'?'AuxBilling':ucfirst($role)).$map[$k]]=$v;} $extra[($role==='auxbilling'?'AuxBilling':ucfirst($role)).'Fax']??='';}
            if($p['role']!=='all'){$extra['ContactType']=strtoupper($p['role']);}
        } elseif(in_array($a,['dns','srv'],true)){
            foreach($p['records'] as $i=>$row){$n=$i+1; $map=$a==='dns'?['name'=>'HostName','type'=>'RecordType','address'=>'Address','mx_pref'=>'MXPref']:['service'=>'Service','protocol'=>'Protocol','target'=>'Target','priority'=>'Priority','weight'=>'Weight','port'=>'Port','host_id'=>'HostID'];
                foreach($map as $k=>$wire){if(isset($row[$k])&&($k!=='mx_pref'||$row['type']==='MX')){$extra[$wire.$n]=$k==='protocol'?strtoupper($row[$k]):$row[$k];}}}
        } elseif(str_starts_with($a,'ds_')){$extra=['Alg'=>$p['ds']['algorithm'],'Digest'=>$p['ds']['digest'],'DigestType'=>$p['ds']['digest_type'],'KeyTag'=>$p['ds']['key_tag']];}
        elseif(str_starts_with($a,'glue_')){$args=[];$extra=match($a){'glue_add'=>['Add'=>'true','NSName'=>$p['host'],'IP'=>$p['ip']],'glue_update'=>['NS'=>$p['host'],'OldIP'=>$p['old_ip'],'NewIP'=>$p['ip']],default=>['NS'=>$p['host']]};}
        $ack=(new ResponseParser)->parse($cmd,$this->write($r,$c,$cmd->value,$args+$extra)); $id=$ack->value('orderid');
        if($a==='auth_code_email') { if($ack->status==='success'&&(Driver::flag($ack->value('infosynched'))===true||is_string($ack->value('eppemailmessage'))&&$ack->value('eppemailmessage')!=='')){$this->acknowledge($r,$c,['email_accepted'=>true]);} $this->owned($r,$c); return new ManagementResult(false,$ack->status==='success'&&(Driver::flag($ack->value('infosynched'))===true||is_string($ack->value('eppemailmessage'))&&$ack->value('eppemailmessage')!==''),[], $ack->status==='rejected'); }
        $facts=[]; if(in_array($a,['privacy_purchase','privacy_renew'],true)&&$ack->status==='success'&&is_string($id)&&preg_match('/^[1-9][0-9]{0,19}$/D',$id)){$facts['order_id']=$id;$this->acknowledge($r,$c,$facts);}
        $observed=isset($facts['order_id'])?new ManagementRequest($r->binding,$r->key,$r->name,$r->action,$p,$r->providerId,$facts['order_id']):$r;
        try{$read=$this->observe($observed,$c);$ok=$read->confirmed;$facts+=$read->facts();}catch(\Throwable){$ok=false;}
        if(in_array($a,['privacy_purchase','privacy_renew'],true)){$ok=$ok&&isset($facts['order_id']);}
        if(str_starts_with($a,'glue_')){$top=$ack->value('nssuccess');$nested=$ack->value(strtolower($cmd->value).'.nssuccess');$ok=$ok&&$ack->status==='success'&&($top??$nested)==='1'&&($top===null||$nested===null||$top===$nested);}
        return new ManagementResult($ok,false,$facts,$ack->status==='rejected');
    }
    public function observe(ManagementRequest $r, RequestContext $c): ManagementResult
    {
        $this->owned($r,$c);$p=$r->payload();$a=$r->action;$facts=[];$ok=false;
        $cmd=match($a){'nameservers'=>R::Nameservers,'contacts'=>R::Contacts,'lock'=>R::Lock,'auto_renew_off'=>R::Renewal,'dns'=>R::Hosts,'srv'=>R::Srv,'ds_add','ds_delete'=>R::Ds,default=>str_starts_with($a,'privacy_')?R::Privacy:(str_starts_with($a,'glue_')?R::HostStatus:null)};
        if(!$cmd){return new ManagementResult($a==='auth_code_email');} $v=$this->get($r,$c,$cmd,$cmd===R::HostStatus?['CheckNSName'=>$p['host']]:null);
        if($cmd!==R::HostStatus&&$v->status!=='success'){return new ManagementResult(false);}
        if($a==='nameservers'){$actual=ManagementRead::nameservers($v);$desired=$p['nameservers'];sort($actual);sort($desired);$ok=($p['use_default']??false)===true?ManagementRead::defaultDns($actual):($actual===$desired||ManagementRead::defaultDns($actual)&&ManagementRead::defaultDns($desired));$facts=['nameservers'=>$actual];}
        elseif($a==='lock'){$state=Driver::flag($v->value('reg-lock'));$ok=$state===$p['locked'];$facts=['locked'=>$state];}
        elseif($a==='auto_renew_off'){$one=Driver::flag($v->value('auto-renew',$v->value('renewname')));$two=$v->value('renewname');$ok=$one===false&&($two===null||Driver::flag($two)===false);$facts=['auto_renew'=>$one];}
        elseif($a==='contacts'){
            $state=Contacts::read($v);$ok=$state!==null;
            foreach($p['role']==='all'?['registrant','admin','tech','auxbilling']:[$p['role']] as $role){if($p['role']==='all'&&$role!=='registrant'&&($state['collected'][$role]??null)===false){continue;}foreach($p['contact'] as $k=>$wanted){if(trim($state['contacts'][$role][$k]??'')!==$wanted){$ok=false;}}}
            $facts=$state??[];
        }elseif(str_starts_with($a,'privacy_')){
            $facts=ManagementRead::privacy($v);$ok=$facts['allowed']===true&&$facts['exists']===true;
            if(in_array($a,['privacy_enable','privacy_disable'],true)){$ok=$ok&&$facts['enabled']===($a==='privacy_enable');}
            else{$expected=$p['privacy_terms']['expected']??null;$days=$expected&&$facts['expiry']?abs((int)(new \DateTimeImmutable($expected))->diff(new \DateTimeImmutable($facts['expiry']))->format('%r%a')):999;
                $ok=$ok&&($a==='privacy_purchase'?$days<=1:$days===0);
                $ok=$ok&&$r->orderId!==null&&ManagementRead::privacyOrder($this->get($r,$c,R::Order,['OrderID'=>$r->orderId]),$r->orderId,$r->name);}
        }elseif(str_starts_with($a,'glue_')){$ip=ManagementRead::glue($v,$p['host']);$ok=$a==='glue_delete'?$ip===null:($ip!==null&&inet_pton($ip)===inet_pton($p['ip']));$facts=['host'=>$p['host'],'ip'=>$ip];}
        else{$kind=str_starts_with($a,'ds_')?'ds':$a;$rows=ManagementRead::records($v,$kind);$facts=['records'=>$rows];$actual=ManagementInput::canonical($kind,$rows);
            if($kind==='ds'){$contains=in_array(ManagementInput::canonical('ds',[$p['ds']])[0],$actual,true);$ok=$contains===($a==='ds_add');}
            else{$ok=$actual===ManagementInput::canonical($kind,$p['records']);}}
        return new ManagementResult($ok,false,$facts);
    }
}
