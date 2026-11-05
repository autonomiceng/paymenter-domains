<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Only the provider's transaction guard is stubbed; native encrypted/owner witnesses live in observation-*-test.php.
namespace Illuminate\Support\Facades { final class DB { public static function transactionLevel(): int { return 0; } } }
namespace {
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Services\RecordFormat;
foreach (['enom','namesilo'] as $registrar) {
    $legacy=['identity'=>['registrar'=>$registrar,'generation'=>1],'at'=>'2026-11-04T12:00:00+00:00','sequence'=>4,
        'families'=>['dns'=>['state'=>'complete','records'=>[]],'srv'=>['state'=>'unknown'],
            'ds'=>['state'=>'unknown'],'glue'=>['state'=>'partial','records'=>['ns.example.com'=>'8.8.8.8']]]];
    $old=RecordFormat::read($legacy,$registrar);
    same($registrar==='enom'?'complete':'unknown',$old['families']['dns']['state'],'Legacy has no new provider completeness');
    $new=$legacy+['format'=>1,'capability_revision'=>$registrar.'-records-1',
        'expected_families'=>['dns'=>'supported','srv'=>'unsupported','ds'=>'unsupported','glue'=>'supported']];
    $new['families']['dns']=['state'=>'partial','records'=>[['opaque'=>'retained CAA and TTL']]];
    $new['families']['srv']=['state'=>'complete','records'=>[]];
    $read=RecordFormat::read($new,$registrar);
    same($new['families']['dns'],$read['families']['dns'],'Partial siblings and opaque fields retained without whole-list authority');
    same('unknown',$read['families']['srv']['state'],'Unsupported is never complete empty');
    $new['families']['dns']=['state'=>'complete','records'=>[]];
    same('complete',RecordFormat::read($new,$registrar)['families']['dns']['state'],'Explicit new supported empty is readable');
    foreach ([['format',2],['capability_revision','future'],['expected_families',['dns'=>'supported']],
        ['format','1'],['expected_families',null]] as [$key,$bad]) {
        $copy=$new;$copy[$key]=$bad;
        same(null,RecordFormat::read($copy,$registrar),'Malformed or unknown observation metadata refused');
    }
    $copy=$new;unset($copy['format']);
    same(null,RecordFormat::read($copy,$registrar),'Half-upgraded metadata is not legacy');

}
echo "Demo C record readers passed.\n";

// NS15 pure codec regression complements the native encrypted model/owner cases.
use Paymenter\Extensions\Servers\Domains\Services\RecordInventory;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Dns,DnsForm,DnsSequence};
foreach (['enom','namesilo'] as $registrar) {
    $dns=$registrar==='enom'?['state'=>'complete','records'=>[['name'=>'@','type'=>'TXT','address'=>' exact ','mx_pref'=>10]]]:
        Dns::inventory(json_encode(['request'=>['operation'=>'dnsListRecords'],'reply'=>['code'=>300,'detail'=>'ok','resource_record'=>[
            ['record_id'=>'A1','type'=>'A','host'=>'example.com','value'=>'8.8.8.8','ttl'=>300,'distance'=>0],
            ['record_id'=>'CAA1','type'=>'CAA','host'=>'example.com','value'=>'0:issue:ca.example','ttl'=>7207,'distance'=>0],
            ['record_id'=>'SRV1','type'=>'SRV','host'=>'_sip._tcp.example.com','value'=>'5:443:sip.example','ttl'=>600,'distance'=>7]]]]),'example.com');
    same($dns,RecordInventory::family('dns',$dns,$registrar,'example.com'),'Provider inventory retains every proven row, ID and TTL');
    $partial=$dns;$partial['records'][]=['type'=>'A','address'=>'invalid'];
    $clean=RecordInventory::family('dns',$partial,$registrar,'example.com');
    same('partial',$clean['state'],'Malformed sibling withdraws completeness');same($dns['records'],$clean['records'],'Valid partial siblings survive');
    same(['state'=>'complete','records'=>[]],RecordInventory::family('dns',['state'=>'complete','records'=>[]],$registrar,'example.com'),'Explicit empty list remains complete');
    $record=['identity'=>['registrar'=>$registrar],'expected_families'=>['dns'=>'supported','srv'=>'unsupported','ds'=>'unsupported','glue'=>'supported'],
        'families'=>['dns'=>$dns,'glue'=>['state'=>$registrar==='enom'?'partial':'complete','records'=>[]]]];
    same(false,RecordInventory::incomplete($record),'Unsupported DS/SRV never create perpetual failures');
    $record['families']['dns']=$clean;same(true,RecordInventory::incomplete($record),'Partial supported DNS cannot certify refresh');
    $record['families']['dns']=$dns;$record['settings']=['contacts'=>['state'=>'partial','facts'=>['registrant'=>[]]]];
    same(true,RecordInventory::incomplete($record),'Partial contacts cannot certify refresh');
    if ($registrar==='namesilo') {
        $view=DnsForm::view($dns);same(['CAA','SRV'],array_column($view['fixed'],'type'),'Opaque siblings stay read-only');
        $wanted=$view['records'];$wanted[0]['address']='1.1.1.1';$payload=DnsForm::payload($dns,$wanted,['0'],$view['digest']);
        $plan=DnsSequence::plan('example.com',$dns,$payload['records']);same(1,count($plan),'A edit never deletes opaque siblings');same(300,$plan[0]['after']['ttl'],'Observed TTL retained');
    }
}
echo "NS15 provider inventory/CAA regressions passed.\n";

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
$clock=new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-11-04T12:00:00+00:00'); } };
foreach (['enom','namesilo'] as $registrar) {
    $b=new AccountBinding($registrar,1,Environment::Test,'synthetic-account');$calls=[];$fault='';$thin=false;$dsRows=[];
    $wire=static function($origin,$cmd,$p,$timeout)use($registrar,&$calls,&$fault,&$thin,&$dsRows):string {
        expect($timeout>0&&$timeout<=25000,'Each physical observation wire is deadline bounded');$calls[]=$cmd;
        if ($cmd===$fault) { return '{}'; }
        if ($registrar==='namesilo') {
            $reply=['code'=>300,'detail'=>'synthetic']+match($cmd) {
                'getDomainInfo'=>['created'=>'2020-01-01','expires'=>'2028-01-01','status'=>'Active','locked'=>'Yes','private'=>'No','auto_renew'=>'No',
                    'nameservers'=>[['nameserver'=>'ns1.dnsowl.com'],['nameserver'=>'ns2.dnsowl.com']],
                    'contact_ids'=>array_fill_keys(['registrant','administrative','technical','billing'],'900001')],
                'dnsListRecords'=>['resource_record'=>[]], 'listRegisteredNameServers'=>['hosts'=>[]],
                'dnsSecListRecords'=>$dsRows===null?[]:['ds_record'=>$dsRows],
                'contactList'=>['contact'=>['contact_id'=>'900001','first_name'=>'Synthetic','last_name'=>'Person','company'=>'','address'=>'1 Example Street','address2'=>'',
                    'city'=>'Portland','state'=>'OR','zip'=>'97201','country'=>'US','email'=>'synthetic@example.com','phone'=>'+1.5035550123']],
                default=>throw new LogicException('Unexpected observation command'),
            };
            return json_encode(['request'=>['operation'=>$cmd],'reply'=>$reply]);
        }
        $contact='<firstname>Synthetic</firstname>'.($thin?'':'<lastname>Person</lastname><address1>1 Example Street</address1><city>Portland</city><postalcode>97201</postalcode><country>US</country><emailaddress>synthetic@example.com</emailaddress><phone>+1.5035550123</phone>');
        $body=match($cmd) {
            'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="456">example.com</DomainName><Status><Belongs-To>synthetic-account</Belongs-To></Status></GetDomainInfo>',
            'GetHosts'=>'<DomainServices><HostRecords>true</HostRecords></DomainServices>',
            'GetDomainSRVHosts'=>'<srv-records></srv-records>',
            'GetDnsSec'=>'<success>true</success><DnsSecDataCount>0</DnsSecDataCount><DnsSecData><result><ResponseCode>200</ResponseCode></result></DnsSecData>',
            'GetDNS'=>'<dns>dns1.name-services.com</dns><dns>dns2.name-services.com</dns>',
            'GetContacts'=>'<GetContacts><registrant>'.$contact.'</registrant><admin>'.$contact.'</admin><tech>'.$contact.'</tech><auxbilling>'.$contact.'</auxbilling></GetContacts>',
            'GetRegLock'=>'<Reg-Lock>1</Reg-Lock>','GetRenew'=>'<RenewName>0</RenewName>',
            'GetWPPSInfo'=>'<GetWPPSInfo><WPPSAllowed>1</WPPSAllowed><WPPSExists>0</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>',
            default=>throw new LogicException('Unexpected observation command'),
        };
        return '<interface-response><Command>'.$cmd.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
    };
    $class='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($registrar==='enom'?'Enom':'NameSilo').'\\ObservationReader';
    $reader=new $class($b,$wire);
    if ($registrar==='namesilo') {
        try { $reader->validate('nameservers',['nameservers'=>['ns1.dnsowl.com','ns2.dnsowl.com']],'example.com');expect(false,'Observation reader must refuse management writes'); }
        catch (DomainException $e) { same('Observation reader is read-only.',$e->getMessage(),'Observation reader refuses its inherited write command'); }
        same([],$calls,'Refused observation write makes no wire request');
    }
    $ctx=fn($budget=25)=>new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget($budget),$clock,stopOnReadFailure:true);
    $request=new ManagementRequest($b,'ns15-read',$registrar==='enom'?'example.com':'example.com','discovery',[],$registrar==='enom'?'456':null);
    $out=$reader->discover($request,$ctx());
    foreach (['nameservers','contacts','flags'] as $kind) { same('complete',$out['settings'][$kind]['state'],'Read helpers discover '.$registrar.' '.$kind); }
    same('complete',$out['dns']['state'],'Helper proves explicit empty DNS');
    if ($registrar==='namesilo') { same(1,count(array_filter($calls,fn($v)=>$v==='contactList')),'At most four exact contact reads; identical IDs are deduplicated'); }
    if ($registrar==='enom') { $thin=true;$out=$reader->discover($request,$ctx());same('partial',$out['settings']['contacts']['state'],'Incomplete contact fields retain readable siblings without certifying completeness');$thin=false; }
    $calls=[];$fault=$registrar==='enom'?'GetDomainSRVHosts':'dnsListRecords';$out=$reader->discover($request,$ctx());
    same($fault,$calls[count($calls)-1],'No wire follows malformed family');
    same($registrar==='enom'?'srv':'dns',$out['failed_family'],'Failure names the specific family');
    $fault='';$calls=[];$reader->discover($request,$ctx(2));same(2,count($calls),'Budget exhaustion cannot make another wire');
    $calls=[];$foreign=new AccountBinding($registrar,2,Environment::Test,'synthetic-account');
    try { $reader->discover(new ManagementRequest($foreign,'foreign','example.com','discovery',[]),$ctx());expect(false,'Foreign reader request refused'); } catch (\Paymenter\Extensions\Servers\Domains\Data\AccountBindingMismatch) {}
    same([],$calls,'Identity mismatch makes no observation wire');
    if ($registrar==='namesilo') {
        // C1: NameSilo DS is readable while DS mutation stays Off (empty policy map).
        same('supported',$reader->observationCapabilities()['families']['ds'],'NameSilo DS read is supported');
        same([Paymenter\Extensions\Servers\Domains\Data\Support::Unsupported,null],[$reader->capabilities()->get('ds')->support,$reader->dsMutationPolicy('example.com')],'DS read support grants no DS mutation');
        same(['state'=>'complete','records'=>[]],$reader->discover($request,$ctx())['ds'],'Explicit empty DS list is complete');
        $tuple=['key_tag'=>123,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('AB',32)];
        $dsRows=[$tuple,['key_tag'=>124,'algorithm'=>13,'digest_type'=>2,'digest'=>'XYZ']];$out=$reader->discover($request,$ctx());
        same(['partial',[$tuple],'ds'],[$out['ds']['state'],$out['ds']['records'],$out['failed_family']],'Malformed DS sibling keeps valid rows partial and names the family');
        $dsRows=null;$calls=[];$out=$reader->discover($request,$ctx());
        same(['unknown','ds','dnsSecListRecords'],[$out['ds']['state'],$out['failed_family'],$calls[count($calls)-1]],'Missing DS collection is unknown, never empty, and stops later reads');
        $dsRows=[$tuple];$out=$reader->discover($request,$ctx());
        same(['complete',[$tuple]],[$out['ds']['state'],$out['ds']['records']],'Resumed complete DS read');expect(!isset($out['failed_family']),'Resume clears the failed family');
    }
}
echo "NS15 bounded paired provider helpers passed.\n";
}
