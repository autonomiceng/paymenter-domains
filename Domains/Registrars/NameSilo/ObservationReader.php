<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Data\{ManagementRequest,RequestContext};
use Paymenter\Extensions\Servers\Domains\Services\{ContactDetails,ManagementPayload,ReadDeferred,EnomTestCallLimit,ReadFailure};
/** Dormant named reads. DS is unsupported until the independent D read contract is integrated. */
final class ObservationReader extends Management
{
    public function observationCapabilities(): array
    { return ['revision'=>'namesilo-records-1','families'=>['dns'=>'supported','srv'=>'unsupported','ds'=>'unsupported','glue'=>'supported']]; }
    protected function writeCommand(string $action): string
    { throw new \DomainException('Observation reader is read-only.'); }
    protected function readCommands(): array { return [...parent::readCommands(),'dnsListRecords']; }
    protected function validateRead(string $command,array $p): void
    { parent::validateRead($command==='dnsListRecords'?'getDomainInfo':$command,$p); }
    public function discover(ManagementRequest $r,RequestContext $c,array $knownGlue=[]): array
    {
        $this->binding->requireMatch($r->binding);$this->binding->requireMatch($c->binding);
        if (count($knownGlue)>50) { throw new \DomainException('Child-host history limit reached.'); }
        $facts=$this->owned($r,$c);
        $out=array_fill_keys(['dns','srv','ds'],['state'=>'unknown','records'=>[]]);
        $out['glue']=['state'=>'partial','records'=>array_filter($knownGlue,fn($ip)=>$ip!=='')];
        $out['settings']=array_fill_keys(['nameservers','contacts','flags'],['state'=>'unknown','facts'=>[]]);
        try { $ns=ManagementPayload::nameservers(array_map(fn($row)=>$row['nameserver']??null,$facts['nameservers']??[]));
            $out['settings']['nameservers']=['state'=>'complete','facts'=>['nameservers'=>$ns]];
        } catch (\Throwable) {}
        $flags=array_intersect_key($facts,array_flip(['locked','privacy','auto_renew']));
        $out['settings']['flags']=['state'=>in_array(null,$flags,true)?'partial':'complete','facts'=>$flags];
        $family='dns';
        try {
            $out['dns']=Dns::inventory($this->read($r,$c,'dnsListRecords',['domain'=>$r->name]),$r->name);
            if ($out['dns']['state']!=='complete') { throw new \DomainException('DNS incomplete.'); }
            $family='glue';
            $reply=$this->reply(Glue::preserveAddresses($this->read($r,$c,'listRegisteredNameServers',['domain'=>$r->name])),'listRegisteredNameServers');
            $out['glue']=['state'=>'complete','records'=>Glue::records($reply,$r->name)];
            $family='contacts';$roles=['registrant'=>'registrant','admin'=>'administrative','tech'=>'technical','auxbilling'=>'billing'];
            $contacts=[];$cache=[];
            foreach ($roles as $role=>$wire) {
                $id=$facts['contact_ids'][$wire]??null;
                if (is_int($id)) { $id=(string)$id; }
                if (!is_string($id)||!preg_match('/^[1-9][0-9]{0,19}$/D',$id)) { continue; }
                if (!isset($cache[$id])) {
                    $reply=$this->reply($this->read($r,$c,'contactList',['contact_id'=>$id]),'contactList');
                    $cache[$id]=$this->contact($reply,$id);
                }
                $contacts[$role]=$cache[$id];
                $out['settings']['contacts']=['state'=>'partial','facts'=>['contacts'=>$contacts]];
            }
            if (count($contacts)!==4) { throw new \DomainException('Contacts incomplete.'); }
            $out['settings']['contacts']['state']='complete';
        } catch (ReadDeferred|EnomTestCallLimit $e) { throw $e; }
        catch (\Throwable $e) { $out['failure_code']=ReadFailure::code($e);$out['failed_family']=$family; }
        return $out;
    }
    private function reply(string $body,string $command): array
    {
        $data=json_decode($body,true,24,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);$p=$data['reply']??null;
        if (($data['request']['operation']??null)!==$command||!is_array($p)||!in_array($p['code']??null,[300,'300'],true)||!is_string($p['detail']??null)) { throw new \DomainException('Observation reply unavailable.'); }
        return $p;
    }
    private function contact(array $reply,string $id): array
    {
        $rows=$reply['contact']??null;
        if (is_array($rows)&&!array_is_list($rows)) { $rows=[$rows]; }
        if (!is_array($rows)||count($rows)!==1||!is_array($rows[0])||(string)($rows[0]['contact_id']??'')!==$id) { throw new \DomainException('Contact identity unavailable.'); }
        $map=['first_name','last_name','company','address','address2','city','state','zip','country','email','phone'];
        $contact=array_combine(array_keys(ContactDetails::limits('namesilo')),array_map(fn($k)=>$rows[0][$k]??null,$map));
        // Observation is descriptive: current write-validation policy must not erase a readable contact.
        foreach (ContactDetails::limits('namesilo') as $field=>$limit) {
            if (!is_string($contact[$field])||strlen($contact[$field])>$limit||preg_match('/[\\x00-\\x1f\\x7f]/',$contact[$field])) {
                throw new \DomainException('Contact fields unavailable.');
            }
        }
        return $contact;
    }
}
