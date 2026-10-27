<?php
// Synthetic read-only contact inventory; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{ResponseParser,RegistrarRequest,Outcome};
$reply=['code'=>300,'detail'=>'synthetic','contact'=>[['contact_id'=>'901','email'=>' NEW@example.com ','default_profile'=>'1']]];
$parse=static fn(array $reply)=>(new ResponseParser)->parse(json_encode(['request'=>['operation'=>'contactList'],'reply'=>$reply],JSON_THROW_ON_ERROR),RegistrarRequest::defaultContact(),'synthetic-key');
$r=$parse($reply);same(Outcome::Success,$r->outcome,'One default contact is a successful read');
same(['contact_id'=>'901','email'=>'new@example.com'],$r->value('default_contact'),'Only validated default fields leave the parser');
$singleton=$reply;$singleton['contact']=$reply['contact'][0];
same(Outcome::Success,$parse($singleton)->outcome,'Singleton contact object is accepted');
foreach(['none','duplicate','bad-id','numeric-id','bad-email','bad-flag','missing','rejected','private-extra'] as $case){
    $v=$reply;
    if($case==='none'){$v['contact'][0]['default_profile']='0';}
    if($case==='duplicate'){$v['contact'][]=$v['contact'][0];}
    if($case==='bad-id'){$v['contact'][0]['contact_id']='not-an-id';}
    if($case==='numeric-id'){$v['contact'][0]['contact_id']=901;}
    if($case==='bad-email'){$v['contact'][0]['email']='not-an-email';}
    if($case==='bad-flag'){$v['contact'][0]['default_profile']='yes';}
    if($case==='missing'){unset($v['contact']);}
    if($case==='rejected'){$v['code']=200;}
    if($case==='private-extra'){$v['contact'][0]['private_extra']='PRIVATE-CONTACT-DATA';}
    $r=$parse($v);
    if($case==='private-extra'){
        same(['contact_id'=>'901','email'=>'new@example.com'],$r->value('default_contact'),'Unknown contact fields never leave the parser');
        expect(!str_contains(json_encode($r),'PRIVATE-CONTACT-DATA'),'Ordinary serialization hides contact data');
    }else{expect($r->outcome!==Outcome::Success,'Invalid contact inventory refuses '.$case);same(null,$r->value('default_contact'),'Invalid inventory exposes no partial contact');}
}
echo "Default contact inventory and refusal shapes checked.\n";
