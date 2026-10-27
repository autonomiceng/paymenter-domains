<?php
// Synthetic Enom 444f31c management fixtures; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/reconciliation-fixture.php';
require_once __DIR__.'/reserved-fixtures.php';
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,HolderPricing,NativeDomainGuard,WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management;
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);$guard->boot();Queue::fake();
$management=new DomainManagement($bindings,$clock,new WriteFeatures(management:true),true);
$state=(object)['name'=>'','calls'=>[],'ns'=>['ns1.example.com','ns2.example.com'],'locked'=>true,'renew'=>false,'privacy'=>false,'enabled'=>false,'expiry'=>'2027-10-18',
    'cost'=>'8.00','balance'=>'100.00','dns'=>[],'srv'=>[],'ds'=>[],'glue'=>[],'contacts'=>[],'fault'=>'','afterWrite'=>null,'during'=>null,'apply'=>true];
$xml=static fn(string $cmd,string $body='')=>'<interface-response><Command>'.$cmd.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
$wrongDomain = reservedFixtureName('example.net');
$exchange=static function(string $origin,string $command,array $p,int $timeout)use($state,$xml,$clock,$wrongDomain):string{
    same(0,DB::transactionLevel(),'Management network outside transaction');same('https://resellertest.enom.com/interface.asp',$origin,'Fixed test origin');expect($timeout>0&&$timeout<=25000,'Bounded timeout');
    $state->calls[]=[$command,$p];if($state->during){$hook=$state->during;$state->during=null;$hook($command);}
    $read=str_starts_with($command,'Get')||$command==='CheckNSStatus';
    if($state->fault===$command){return 'malformed';}
    if(!$read&&$state->fault==='refused'){return '<interface-response><Command>'.$command.'</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>524181</RRPCode><errors><Err1>Insufficient balance</Err1></errors></interface-response>';}
    if(!$read && $state->apply){
        if($command==='ModifyNS'){$state->ns=array_values(array_filter($p,fn($k)=>preg_match('/^NS[0-9]+$/D',$k),ARRAY_FILTER_USE_KEY));}
        if($command==='SetRegLock'){$state->locked=$p['UnlockRegistrar']===0;}
        if($command==='SetRenew'){$state->renew=false;}
        if($command==='Contacts'){
            $map=['FirstName'=>'first_name','LastName'=>'last_name','OrganizationName'=>'organization_name','JobTitle'=>'job_title','Address1'=>'address1','Address2'=>'address2','City'=>'city','StateProvince'=>'state_province','PostalCode'=>'postal_code','Country'=>'country','EmailAddress'=>'email_address','Phone'=>'phone','PhoneExt'=>'phone_ext','Fax'=>'fax'];
            foreach(['Registrant','Admin','Tech','Auxbilling'] as $role){foreach($map as $wire=>$key){if(isset($p[($role==='Auxbilling'?'AuxBilling':$role).$wire])){$state->contacts[strtolower($role)][$wire]=$p[($role==='Auxbilling'?'AuxBilling':$role).$wire];}}}
        }
        if(in_array($command,['PurchaseServices','RenewServices'],true)){$state->privacy=true;$state->enabled=true;$state->expiry=\Carbon\CarbonImmutable::parse($command==='PurchaseServices'?$clock->now()->format('Y-m-d'):$state->expiry)->addYearNoOverflow()->format('Y-m-d');}
        if($command==='EnableServices'){$state->enabled=true;}if($command==='DisableServices'){$state->enabled=false;}
        if($command==='SetHosts'){$state->dns=[];for($i=1;isset($p['HostName'.$i]);$i++){$state->dns[]=['name'=>$p['HostName'.$i],'type'=>$p['RecordType'.$i],'address'=>$p['Address'.$i],'mx_pref'=>$p['MXPref'.$i]??10];}}
        if($command==='SetDomainSRVHosts'){$state->srv=[];for($i=1;isset($p['Service'.$i]);$i++){$state->srv[]=['service'=>$p['Service'.$i],'protocol'=>strtolower($p['Protocol'.$i]),'target'=>$p['Target'.$i],'priority'=>$p['Priority'.$i],'weight'=>$p['Weight'.$i],'port'=>$p['Port'.$i]];}}
        if($command==='AddDnsSec'){$state->ds[]=['algorithm'=>$p['Alg'],'digest'=>$p['Digest'],'digest_type'=>$p['DigestType'],'key_tag'=>$p['KeyTag']];}if($command==='DeleteDnsSec'){$state->ds=[];}
        if($command==='RegisterNameServer'){$state->glue[$p['NSName']]=$p['IP'];}if($command==='UpdateNameServer'){$state->glue[$p['NS']]=$p['NewIP'];}if($command==='DeleteNameServer'){unset($state->glue[$p['NS']]);}
    }
    $body=match($command){
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">'.$state->name.'</DomainName><Status><Belongs-To>'.($state->fault==='owner'?'another-account':'account-enom').'</Belongs-To></Status></GetDomainInfo>',
        'GetOrderDetail'=>'<Order><OrderID>'.($state->fault==='order_id'?'7002':$p['OrderID']).'</OrderID><Result>true</Result><OrderDetail><Description>'.($state->fault==='order_domain'?$wrongDomain:$state->name).'</Description><Status>'.($state->fault==='order_pending'?'pending':'successful').'</Status><OrderStatus>closed</OrderStatus><Quantity>'.($state->fault==='order_term'?'2':'1').'</Quantity></OrderDetail></Order>',
        'GetRegLock'=>'<Reg-Lock>'.(int)$state->locked.'</Reg-Lock>', 'GetRenew'=>'<RenewName>'.(int)$state->renew.'</RenewName>',
        'GetWPPSInfo'=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>'.(int)$state->privacy.'</WPPSExists><WPPSEnabled>'.(int)$state->enabled.'</WPPSEnabled><WPPSAutoRenew>0</WPPSAutoRenew><WPPSExpDate>'.$state->expiry.'</WPPSExpDate><WPPSPrice>'.$state->cost.'</WPPSPrice></GetWPPSInfo>',
        'GetBalance'=>'<AvailableBalance>'.$state->balance.'</AvailableBalance>',default=>''};
    if($command==='GetDNS'){foreach($state->ns as $i=>$ns){$body.='<dns>'.$ns.'</dns>';}}
    if($command==='GetContacts'){$body='<GetContacts>';foreach($state->contacts as $role=>$fields){$body.='<'.$role.'>';foreach($fields as $k=>$v){$body.='<'.$k.'>'.htmlspecialchars($v,ENT_XML1).'</'.$k.'>';}$body.='</'.$role.'>';}$body.='</GetContacts>';}
    if($command==='GetHosts'){$body='<DomainServices><HostRecords>true</HostRecords></DomainServices>';foreach($state->dns as $row){$body.='<host><name>'.$row['name'].'</name><type>'.$row['type'].'</type><address>'.htmlspecialchars($row['address'],ENT_XML1).'</address><mxpref>'.$row['mx_pref'].'</mxpref></host>';}}
    if($command==='GetDomainSRVHosts'){$body='<srv-records>';foreach($state->srv as $row){$body.='<srv><hostname>_'.$row['service'].'</hostname><protocol>_'.$row['protocol'].'</protocol><priority>'.$row['priority'].'</priority><weight>'.$row['weight'].'</weight><port>'.$row['port'].'</port><address>'.$row['target'].'</address></srv>';}$body.='</srv-records>';}
    if($command==='GetDnsSec'){$body='<success>true</success><DnsSecDataCount>'.count($state->ds).'</DnsSecDataCount><DnsSecData><result><ResponseCode>200</ResponseCode></result>';foreach($state->ds as $row){$body.='<KeyData><algorithm>'.$row['algorithm'].'</algorithm><digest>'.$row['digest'].'</digest><digesttype>'.$row['digest_type'].'</digesttype><keytag>'.$row['key_tag'].'</keytag></KeyData>';}$body.='</DnsSecData>';}
    if($command==='CheckNSStatus'){$host=$p['CheckNSName'];if(!isset($state->glue[$host])){return '<interface-response><Command>CheckNSStatus</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>545</RRPCode><errors><Err1>Error 545 - Name server does not exist</Err1></errors></interface-response>';}$body='<NsCheckSuccess>1</NsCheckSuccess><CheckNsStatus><NsName>'.$host.'</NsName><IpAddress>'.$state->glue[$host].'</IpAddress></CheckNsStatus>';}
    if(!$read&&$state->afterWrite){$hook=$state->afterWrite;$state->afterWrite=null;$hook($command);}
    if(!$read){$body.='<InfoSynched>true</InfoSynched><'.$command.'><NsSuccess>1</NsSuccess></'.$command.'><OrderID>7001</OrderID>';
        if($state->fault==='empty_ack'){$body='';}}
    return $xml($command,$body);
};
$holding=static function(string $name, ?\Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence $nameservers = null)use($prepare,$reconcile,$state,$exchange,$wrongDomain){
    expect($name !== $wrongDomain, 'Wrong-domain response remains distinct from the holding');
    [$original,$binding]=$prepare($name, 'accepted', $nameservers);same('succeeded',$reconcile->run($original->id)->state,'Real uniquely completed registration anchor');$d=$original->domain()->firstOrFail();
    $state->name=$name;$state->locked=true;$state->dns=[];$state->srv=[];$state->ds=[];$state->glue=[];$state->contacts=[];$state->calls=[];$state->fault='';$state->afterWrite=null;$state->during=null;$state->apply=true;Cache::flush();
    return [$d,new Management($binding,$exchange),$original];
};
$seq=0;$key=static function()use(&$seq){return hash('sha256','management-'.++$seq);};
$submit=static function($d,$driver,string $action,array $payload=[])use($management,$user,$key){return $management->request($user,$d->id,$action,$payload,$key(),$driver);};
