<?php
// Reviewed synthetic management replies, no sockets; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$manageReply=static function(array $p)use($s,$reply):string {
    same(0,Illuminate\Support\Facades\DB::transactionLevel(),'Management physical request outside locks');
    $c=$p['Command'];if($s->fault===$c){return 'malformed';}
    if($c==='ModifyNS'){$s->ns=array_values(array_filter($p,static fn($k)=>preg_match('/^NS[0-9]+$/D',$k),ARRAY_FILTER_USE_KEY));}
    if($c==='SetRegLock'){$s->locked=$p['UnlockRegistrar']==='0';}
    if($c==='SetHosts'){$s->dns=[];for($i=1;isset($p['HostName'.$i]);$i++){$s->dns[]=['name'=>$p['HostName'.$i],'type'=>$p['RecordType'.$i],'address'=>$p['Address'.$i],'mx_pref'=>$p['MXPref'.$i]??10];}}
    if($c==='SetDomainSRVHosts'){$s->srv=[];for($i=1;isset($p['Service'.$i]);$i++){$s->srv[]=['service'=>$p['Service'.$i],'protocol'=>strtolower($p['Protocol'.$i]),'target'=>$p['Target'.$i],'priority'=>$p['Priority'.$i],'weight'=>$p['Weight'.$i],'port'=>$p['Port'.$i]];}}
    if($c==='AddDnsSec'){$s->ds[]=['algorithm'=>$p['Alg'],'digest'=>$p['Digest'],'digest_type'=>$p['DigestType'],'key_tag'=>$p['KeyTag']];}if($c==='DeleteDnsSec'){$s->ds=[];}
    $body=match($c){
        'GetDomainInfo'=>'<GetDomainInfo><DomainName DomainNameID="'.$s->id.'">paid-test-placeholder.com</DomainName><Status><Belongs-To>'.$s->owner.'</Belongs-To><Expiration>2027-10-21</Expiration></Status></GetDomainInfo>',
        'GetDNS'=>implode('',array_map(static fn($n)=>'<dns>'.$n.'</dns>',$s->ns)),
        'GetRegLock'=>'<Reg-Lock>'.(int)$s->locked.'</Reg-Lock>',
        'GetHosts'=>'<DomainServices><HostRecords>true</HostRecords></DomainServices>'.implode('',array_map(static fn($v)=>'<host><name>'.$v['name'].'</name><type>'.$v['type'].'</type><address>'.htmlspecialchars($v['address'],ENT_XML1).'</address><mxpref>'.$v['mx_pref'].'</mxpref></host>',$s->dns)),
        'GetDomainSRVHosts'=>'<srv-records>'.implode('',array_map(static fn($v)=>'<srv><hostname>_'.$v['service'].'</hostname><protocol>_'.$v['protocol'].'</protocol><priority>'.$v['priority'].'</priority><weight>'.$v['weight'].'</weight><port>'.$v['port'].'</port><address>'.$v['target'].'</address></srv>',$s->srv??[])).'</srv-records>',
        'GetDnsSec'=>'<success>true</success><DnsSecDataCount>'.count($s->ds??[]).'</DnsSecDataCount><DnsSecData><result><ResponseCode>200</ResponseCode></result>'.implode('',array_map(static fn($v)=>'<KeyData><algorithm>'.$v['algorithm'].'</algorithm><digest>'.$v['digest'].'</digest><digesttype>'.$v['digest_type'].'</digesttype><keytag>'.$v['key_tag'].'</keytag></KeyData>',$s->ds??[])).'</DnsSecData>',
        'ModifyNS','SetRegLock','SetHosts','SetDomainSRVHosts','AddDnsSec','DeleteDnsSec'=>'',
        default=>null,
    };
    return $body===null?$reply($p):'<interface-response><Command>'.$c.'</Command><ErrCount>0</ErrCount><Done>true</Done>'.$body.'</interface-response>';
};
