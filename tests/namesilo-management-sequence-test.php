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
$nativeSuccessful=true;echo "NameSilo management normalization and automatic contact sequence passed.\n";
