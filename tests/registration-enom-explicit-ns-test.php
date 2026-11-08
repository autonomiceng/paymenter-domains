<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-enom.php';
$base=$exchange;
$wire=static function($origin,$command,$params,$timeout)use($base):string {
    if($command==='Purchase'){
        same(['ns1.provider.example','ns2.provider.example'],[$params['NS1']??null,$params['NS2']??null],'Initial Purchase submits the frozen explicit nameservers');
        expect(!isset($params['UseDNS']),'Configured Purchase does not request registrar defaults');
        return '<interface-response><Command>Purchase</Command><ErrCount>0</ErrCount><Done>true</Done><RRPCode>200</RRPCode><OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate></interface-response>';
    }
    return $base($origin,$command,$params,$timeout);
};
$makeEngine($binding,$wire);$registration->mode='clean';
$op=$new('explicit-purchase.example.com');$result=$runRegistration($op);
same('pending',$result->state,'Explicit-DNS Purchase retains normal completion proof');
$nativeSuccessful=true;echo "Purchase explicit nameserver submission passed\n";
