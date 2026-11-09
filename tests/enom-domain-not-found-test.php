<?php
// Synthetic account data only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';

use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ReadCommand, ResponseParser};

// Only the exact observed not-found response can stop reads as outside this account.
$missing='<interface-response><Command>GetDomainInfo</Command><ErrCount>1</ErrCount><Done>true</Done>'
    .'<errors><Err1>Domain name not found</Err1></errors><responses><response><ResponseNumber>301029</ResponseNumber>'
    .'<ResponseString>Validation error; not found; domain name(s)</ResponseString></response></responses></interface-response>';
$absence=(new ResponseParser)->parse(ReadCommand::Info,$missing);
same(['rejected','domain_not_in_account'],[$absence->status,$absence->code],'Exact not-found response supplies absence evidence');
foreach(['<DomainName>example.com</DomainName>','<RRPCode>200</RRPCode>','<ResponseCount>2</ResponseCount>',
    '<debug><private>PRIVATE-TOKEN</private></debug>'] as $contradiction){
    $ambiguous=(new ResponseParser)->parse(ReadCommand::Info,str_replace('</interface-response>',$contradiction.'</interface-response>',$missing));
    expect($ambiguous->code!=='domain_not_in_account','Contradictory account evidence cannot prove absence');
}
same(null,(new ResponseParser)->parse(ReadCommand::Info,str_replace('Domain name not found','PRIVATE-TOKEN',$missing))->value('errors'),'An arbitrary account error stays private');
// A counted reply proves absence only with the one matching response it counts.
$counted=str_replace('<responses>','<ResponseCount>1</ResponseCount><responses>',$missing);
same(['rejected','domain_not_in_account'],[(new ResponseParser)->parse(ReadCommand::Info,$counted)->status,(new ResponseParser)->parse(ReadCommand::Info,$counted)->code],'A counted reply with its matching response proves absence');
$uncounted=preg_replace('#<responses>.*</responses>#s','',$counted);
foreach(['missing'=>$uncounted,'empty'=>str_replace('</errors>','</errors><responses></responses>',$uncounted),
    'self-closed'=>str_replace('</errors>','</errors><responses/>',$uncounted),
    'contradictory'=>str_replace('Validation error; not found; domain name(s)','Command completed successfully',$counted)] as $case=>$body){
    expect(substr_count($body,'<ResponseCount>1</ResponseCount>')===1,'Counted '.$case.' fixture');
    expect((new ResponseParser)->parse(ReadCommand::Info,$body)->code!=='domain_not_in_account','A counted reply with a '.$case.' response cannot prove absence');
}
same('domain_not_in_account',(new ResponseParser)->parse(ReadCommand::Info,preg_replace('#<responses>.*</responses>#s','',$missing))->code,'The uncounted reply keeps its accepted shape');
// Absence evidence is a read result only. The recorded purchase refusal keeps its own rules.
require __DIR__.'/Support/registration-refusal-reply.php';
$refusal=(new ResponseParser)->parse(Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\RegistrationCommand::Purchase,$purchaseRefusal);
expect($refusal->status==='rejected'&&$refusal->value('purchase_refusal')!==null,'The recorded purchase refusal still carries refusal proof');
foreach([$counted,$uncounted] as $body){
    $purchase=(new ResponseParser)->parse(Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\RegistrationCommand::Purchase,str_replace('GetDomainInfo','Purchase',$body));
    expect($purchase->code!=='domain_not_in_account'&&$purchase->value('purchase_refusal')===null,'A not-found reply never becomes purchase refusal proof');
}

foreach (['Purchase', 'ModifyNS', 'DeleteNameServer', 'UpdateNameServer', 'Extend', 'GetAllDomains'] as $write) {
    same(null, ReadCommand::tryFrom($write), 'Write and retired commands stay unavailable through reads');
}

$nativeSuccessful = true;
echo "Enom domain not-found read passed.\n";
