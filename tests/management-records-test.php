<?php
// Enom 444f31c DNS/SRV/DS/glue scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{ManagementInput as I,ManagementRead};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand,ResponseParser};
[$d,$driver]=$holding(placeholderComFixtureName('manage-records-placeholder.com'));
$ds=['algorithm'=>13,'digest_type'=>2,'key_tag'=>123,'digest'=>str_repeat('A',64)];
commerceReject(fn()=>$submit($d,$driver,'ds_add',['ds'=>$ds]));same([],$state->calls,'DNS tools default off at request boundary');
$product->server->settings()->create(['key'=>'domains_dns_tools','value'=>true,'type'=>'boolean']);
$records=[['name'=>'@','type'=>'TXT','address'=>'  exact TXT spaces  ','mx_pref'=>10],['name'=>'mail','type'=>'MX','address'=>'mail.example.com.','mx_pref'=>5]];
$op=$submit($d,$driver,'dns',['records'=>$records,'preimage_digest'=>I::digest([])]);same('succeeded',$management->run($op->id,$driver)->state,'TXT/MX full replacement readback');same('  exact TXT spaces  ',$op->fresh()->attempt['management_reads'][0]['facts']['records'][0]['address'],'TXT spaces preserved by parser');
$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='SetHosts'))[0][1];same(false,isset($write['MXPref1']),'Non-MX preference omitted');same(5,$write['MXPref2'],'MX exact preference');
Cache::flush();$rows=[['service'=>'sip','protocol'=>'tcp','target'=>'sip.example.com.','priority'=>0,'weight'=>5,'port'=>5060,'host_id'=>42]];
$op=$submit($d,$driver,'srv',['records'=>$rows,'preimage_digest'=>I::digest([])]);same('succeeded',$management->run($op->id,$driver)->state,'SRV readback ignores provider host ID');
$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='SetDomainSRVHosts'))[0][1];same('TCP',$write['Protocol1'],'SRV protocol uppercase');same(42,$write['HostID1'],'Existing SRV host ID sent');
Cache::flush();$op=$submit($d,$driver,'ds_add',['ds'=>$ds]);same('succeeded',$management->run($op->id,$driver)->state,'DS exact tuple added');Cache::flush();$op=$submit($d,$driver,'ds_delete',['ds'=>$ds]);same('succeeded',$management->run($op->id,$driver)->state,'DS verified zero readback delete');
$parse=static fn($b)=> (new ResponseParser)->parse(ReadCommand::Ds,$xml('GetDnsSec',$b));
$tuple='<algorithm>13</algorithm><digest>'.str_repeat('a',64).'</digest><digesttype>2</digesttype><keytag>123</keytag>';
same([I::ds($ds)],ManagementRead::records($parse($tuple),'ds'),'Documented flat DS tuple');
foreach(['<success>true</success><DnsSecDataCount>0</DnsSecDataCount><DnsSecData><result><ResponseCode>200</ResponseCode></result><KeyData>'.$tuple.'</KeyData></DnsSecData>',
    '<success>true</success><DnsSecDataCount>2</DnsSecDataCount><DnsSecData><result><ResponseCode>200</ResponseCode></result><KeyData>'.$tuple.'</KeyData><KeyData>'.$tuple.'</KeyData></DnsSecData>',
    '<success>false</success>'.$tuple,'<success>true</success><DnsSecDataCount>1</DnsSecDataCount><DnsSecData><result><ResponseCode>500</ResponseCode></result><KeyData>'.$tuple.'</KeyData></DnsSecData>'] as $bad){commerceReject(fn()=>ManagementRead::records($parse($bad),'ds'));}
[$d,$driver]=$holding(placeholderComFixtureName('manage-preimage-placeholder.com'));$op=$submit($d,$driver,'dns',['records'=>$records,'preimage_digest'=>I::digest([])]);$state->dns=$records;same('held',$management->run($op->id,$driver)->state,'Stale full-list preimage refuses write');same(['GetDomainInfo','GetHosts'],array_column($state->calls,0),'Preimage failure never writes');
foreach([['dns',['records'=>[],'preimage_digest'=>I::digest([])]],['dns',['records'=>[['name'=>'@','type'=>'CNAME','address'=>'missing-dot']],'preimage_digest'=>I::digest([])]],['srv',['records'=>[array_replace($rows[0],['port'=>65536])],'preimage_digest'=>I::digest([])]],['ds_add',['ds'=>array_replace($ds,['digest'=>str_repeat('a',63)])]],['ds_add',['ds'=>array_replace($ds,['algorithm'=>255])]]] as [$a,$p]){commerceReject(fn()=>I::validate($a,$p,$d->domain));}
[$d,$driver]=$holding(placeholderComFixtureName('manage-glue-placeholder.com'));$host='ns1.'.$d->domain;
foreach(['10.0.0.1','100.64.0.1','127.0.0.1','192.0.2.1','198.18.0.1','224.0.0.1','::1','::ffff:8.8.8.8','64:ff9b::808:808','2001:db8::1','2001::1','fc00::1','fe80::1','ff02::1','not-ip'] as $ip){commerceReject(fn()=>I::validate('glue_add',['host'=>$host,'ip'=>$ip],$d->domain));}
commerceReject(fn()=>I::validate('glue_add',['host'=>'ns1.other-placeholder.com','ip'=>'8.8.8.8'],$d->domain));
$op=$submit($d,$driver,'glue_add',['host'=>strtoupper($host).'.','ip'=>'2606:4700:4700::1111']);same('succeeded',$management->run($op->id,$driver)->state,'Public IPv6 glue add and fresh status');
Cache::flush();$op=$submit($d,$driver,'glue_update',['host'=>$host,'ip'=>'8.8.8.8']);$state->during=static fn()=>Cache::put('domains-write-slot:'.$op->account_fingerprint,true,25);same('queued',$management->run($op->id,$driver)->state,'Glue slot race remains unsent');Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Glue update retries fresh preparation');$write=array_values(array_filter($state->calls,fn($v)=>$v[0]==='UpdateNameServer'))[0][1];same('2606:4700:4700::1111',$write['OldIP'],'Fresh provider old IP sent');
Cache::flush();$op=$submit($d,$driver,'glue_delete',['host'=>$host]);same('succeeded',$management->run($op->id,$driver)->state,'Only exact 545 absence confirms deletion');
[$d,$driver]=$holding(placeholderComFixtureName('manage-glue-unknown-placeholder.com'));$state->fault='CheckNSStatus';$op=$submit($d,$driver,'glue_add',['host'=>'ns1.'.$d->domain,'ip'=>'8.8.8.8']);same('held',$management->run($op->id,$driver)->state,'Unknown host status never means absent');
foreach([['A','8.8.8.8'],['AAAA','2606:4700:4700::1111'],['CNAME','alias.example.com.'],['URL','https://example.com/path'],['FRAME','http://example.com/'],['MXE','1.1.1.1']] as [$type,$address]){same($address,I::host(['name'=>'www','type'=>$type,'address'=>$address])['address'],'Source DNS type validated');}
same(I::digest($records),I::digest(array_reverse($records)),'DNS digest ignores provider row order');same(I::digest($rows,'srv'),I::digest([array_replace($rows[0],['host_id'=>99])],'srv'),'SRV digest ignores provider IDs');
$absent='<interface-response><Command>CheckNSStatus</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>545</RRPCode><errors><Err1>Error 545 - Name server does not exist</Err1></errors><NsCheckSuccess>1</NsCheckSuccess><CheckNsStatus><IpAddress>8.8.8.8</IpAddress></CheckNsStatus></interface-response>';
commerceReject(fn()=>ManagementRead::glue((new ResponseParser)->parse(ReadCommand::HostStatus,$absent),'ns1.example.com'));
foreach(['dns'=>'GetHosts','srv'=>'GetDomainSRVHosts'] as $kind=>$command){foreach(['','<unrelated/>'] as $missing){commerceReject(fn()=>ManagementRead::records((new ResponseParser)->parse(ReadCommand::from($command),$xml($command,$missing)),$kind));}}
same([],ManagementRead::records((new ResponseParser)->parse(ReadCommand::Hosts,$xml('GetHosts','<DomainServices><HostRecords>true</HostRecords></DomainServices>')),'dns'),'Explicit empty DNS supported');same([],ManagementRead::records((new ResponseParser)->parse(ReadCommand::Srv,$xml('GetDomainSRVHosts','<srv-records/>')),'srv'),'Explicit empty SRV supported');
// Provider spellings share one semantic DNS comparison and editor digest; text values remain exact.
$compressed=[['name'=>'WWW','type'=>'AAAA','address'=>'2001:db8:abcd::1','mx_pref'=>10]];
$expanded=[['name'=>'www','type'=>'AAAA','address'=>'2001:0DB8:ABCD:0000:0000:0000:0000:0001','mx_pref'=>10]];
same(I::canonical('dns',$compressed),I::canonical('dns',$expanded),'Equivalent IPv6 canonical tuples');
same(I::digest($compressed),I::digest($expanded),'Equivalent IPv6 editor digest');
expect(I::digest($compressed)!==I::digest([array_replace($compressed[0],['address'=>'2001:db8:abcd::2'])]),'Changed IPv6 is distinct');
foreach(['A','MXE'] as $type){same('8.8.8.8',I::canonical('dns',[['name'=>'@','type'=>$type,'address'=>'8.8.8.8']])[0]['address'],'IPv4 canonical family');}
$parseHosts=static function(array $rows)use($xml){$body='<DomainServices><HostRecords>true</HostRecords></DomainServices>';foreach($rows as $row){$body.='<host><name>'.$row['name'].'</name><type>'.$row['type'].'</type><address>'.htmlspecialchars($row['address'],ENT_XML1).'</address><mxpref>'.($row['mx_pref']??10).'</mxpref></host>';}return ManagementRead::records((new ResponseParser)->parse(ReadCommand::Hosts,$xml('GetHosts',$body)),'dns');};
foreach(['CNAME','MX'] as $type){
    $dotted=[['name'=>'mail','type'=>$type,'address'=>'Mail.Example.COM.','mx_pref'=>$type==='MX'?5:10]];
    $undotted=[array_replace($dotted[0],['address'=>'Mail.Example.COM'])];
    same($dotted,$parseHosts($undotted),'Provider '.$type.' root dot supplied before strict validation');
    commerceReject(fn()=>I::validate('dns',['records'=>$undotted,'preimage_digest'=>I::digest([])],'example.com'));
    foreach(['bad..example.com','mail.example.com..','-bad.example.com','bad-.example.com','bad_label.example.com','single',' mail.example.com','mail.example.com ',"mail.\texample.com", "mail.\nexample.com","mail.\x01example.com"] as $target){commerceReject(fn()=>$parseHosts([array_replace($dotted[0],['address'=>$target])]));commerceReject(fn()=>I::host(array_replace($dotted[0],['address'=>$target])));}
    [$d,$driver]=$holding('manage-provider-'.strtolower($type).'.com');
    $state->dns=$undotted;
    $op=$submit($d,$driver,'dns',['records'=>$dotted,'preimage_digest'=>I::digest($dotted)]);
    $state->afterWrite=static function($command)use($state,$undotted){if($command==='SetHosts'){$state->dns=$undotted;}};
    same('succeeded',$management->run($op->id,$driver)->state,'Undotted '.$type.' preimage, write and observation confirm');
    // Missing first observation retains uncertainty, then read-only reconciliation handles undotted values.
    Cache::flush();$op=$submit($d,$driver,'dns',['records'=>$dotted,'preimage_digest'=>I::digest($dotted)]);
    $state->afterWrite=static function($command)use($state,$undotted){if($command==='SetHosts'){$state->dns=$undotted;$state->fault='GetHosts';}};
    same('uncertain',$management->run($op->id,$driver)->state,'Unavailable initial DNS observation stays uncertain');
    $state->fault='';same('succeeded',$management->reconcile($op->id,$driver)->state,'Undotted '.$type.' reconciliation confirms');
    same(2,count(array_filter($state->calls,fn($v)=>$v[0]==='SetHosts')),'Reconciliation never replays DNS write');
}
[$d,$driver]=$holding(placeholderComFixtureName('manage-provider-ip-placeholder.com'));$state->dns=$expanded;
$op=$submit($d,$driver,'dns',['records'=>$compressed,'preimage_digest'=>I::digest($compressed)]);
$state->afterWrite=static function($command)use($state,$expanded){if($command==='SetHosts'){$state->dns=$expanded;}};
same('succeeded',$management->run($op->id,$driver)->state,'Equivalent provider IPv6 survives preimage/write/observe');
$mx=[['name'=>'@','type'=>'MX','address'=>'a.example.com.','mx_pref'=>5],['name'=>'@','type'=>'MX','address'=>'b.example.com.','mx_pref'=>10]];
same(I::digest($mx),I::digest(array_reverse($mx)),'MX row order ignored');
expect(I::digest($mx)!==I::digest([array_replace($mx[0],['mx_pref'=>10]),$mx[1]]),'MX preference significant');
foreach(['2001:0DB8:ABCD:0000:0000:0000:0000:0001','  2001:db8::1  ','mail.example.com',' mail.example.com. '] as $text){
    $row=['name'=>'txt','type'=>'TXT','address'=>$text,'mx_pref'=>10];same([$row],$parseHosts([$row]),'Provider TXT bytes exact');
    same($text,I::canonical('dns',[$row])[0]['address'],'Canonical TXT bytes exact');
    expect(I::digest([$row])!==I::digest([array_replace($row,['address'=>$text.' '])]),'TXT whitespace changes digest');
}
foreach(['URL'=>'https://EXAMPLE.com/a%20b','FRAME'=>'http://example.com/Case?ip=2001:db8::1'] as $type=>$text){$row=['name'=>'web','type'=>$type,'address'=>$text,'mx_pref'=>10];same([$row],$parseHosts([$row]),'Provider redirect bytes exact');same($text,I::canonical('dns',[$row])[0]['address'],'Canonical redirect bytes exact');foreach([' '.$text,$text.' '] as $bad){commerceReject(fn()=>$parseHosts([array_replace($row,['address'=>$bad])])) ;}}

$nativeSuccessful=true;echo "management records passed\n";
