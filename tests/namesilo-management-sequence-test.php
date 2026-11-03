<?php
// Synthetic management contacts through the genuine durable engine. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management;

[$d,,$original]=$holding('phone-equivalence');
$input=['role'=>'tech','contact'=>$original->payload['contacts']['tech']];
$formatted=static function($origin,$command,$p,$timeout)use($managementExchange):string {
    $body=$managementExchange($origin,$command,$p,$timeout);
    if($command==='contactList'){
        $data=json_decode($body,true,24,JSON_THROW_ON_ERROR);$phone=$data['reply']['contact']['phone'];
        $data['reply']['contact']['phone']=substr($phone,0,3).'-'.substr($phone,3,3).'-'.substr($phone,6);
        return json_encode($data,JSON_THROW_ON_ERROR);
    }return $body;
};
$driver=new Management($binding,$formatted);
$stage=$submit($d,$driver,'contact_profile',$input);
same('succeeded',$management->run($stage->id,$driver)->state,'Documented US/CA phone spelling verifies the isolated management profile');
// A single owner intent durably owns both mutations, with no staging submission.
[$d,$driver,$original]=$holding('automatic');
$input=['role'=>'tech','contact'=>$original->payload['contacts']['tech']];$nonce=hash('sha256','automatic');
$op=$management->request($user,$d->id,'contacts',$input,$nonce,$driver);
same('contacts',$op->action,'The owner request remains the contact change');
same('queued',$management->run($op->id,$driver)->state,'Creation finishes before association can claim a new write slot');
same(['contactAdd'],array_column($writes(),0),'One automatic isolated profile creation');
Cache::flush();
same('succeeded',$management->run($op->id,$driver)->state,'The same owner request automatically associates its verified profile');
same(['contactAdd','contactDomainAssociate'],array_column($writes(),0),'Each command is sent once');
$association=end($ms->calls);$sent=array_values(array_filter($ms->calls,fn($c)=>$c[0]==='contactDomainAssociate'))[0][1];
same(['domain','technical'],array_keys($sent),'Only the selected role is associated');
same($op->id,$management->request($user,$d->id,'contacts',$input,$nonce,$driver)->id,'Request replay keeps the original sequence');
$management->run($op->id,$driver);same(2,count($writes()),'Completed sequence cannot replay either mutation');
$nativeSuccessful=true;echo "NameSilo management normalization and automatic contact sequence passed.\n";
