<?php
// One owner intent through native authority, durable reservations and namespace-faked physical cURL.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
require __DIR__.'/bootstrap.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$transferEnvironment=Environment::Live;
require __DIR__.'/Support/management-namesilo.php';require __DIR__.'/Support/namesilo-write-wire.php';
\Paymenter\Extensions\Servers\Domains\Services\LiveReservationSchema::install();
$legacyTransferExchange=$exchange;$exchange=static fn($origin,$cmd,$p,$ms)=>$legacyTransferExchange(str_replace('https://www.namesilo.com','https://ote.namesilo.com',$origin),$cmd,$p,$ms);
[$d,,$original]=$holding('contact-sequence-wire');$ms->route='apibatch';$transport=nsWriteTransport($binding,$clock);
$driver=new Management($binding,$transport->exchange());$driver->useReservation($transport->reservation);
$GLOBALS['nsReply']=static function($cmd,$p)use($managementExchange):string {
    $body=$managementExchange('https://ote.namesilo.com/apibatch',$cmd,$p,25000);
    if($cmd==='contactList'){$data=json_decode($body,true,24,JSON_THROW_ON_ERROR);$phone=$data['reply']['contact']['phone'];
        $data['reply']['contact']['phone']=substr($phone,0,3).'-'.substr($phone,3,3).'-'.substr($phone,6);return json_encode($data,JSON_THROW_ON_ERROR);}
    return $body;
};
$money=static fn()=>array_map(static fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),['invoices','invoice_items','invoice_transactions','credits','jobs','failed_jobs']);
$before=$money();$usage=nsUsage($binding);$purchase=$original->attempt;
$op=$submit($d,$driver,'contacts',['role'=>'registrant','contact'=>$original->payload['contacts']['registrant']]);
$stage=$d->operations()->where('action','contact_profile')->sole();$GLOBALS['nsOperation']=$stage->id;
same('queued',$management->run($op->id,$driver,RequestOrigin::Background)->state,'Profile creation owns its own admitted call and write slot');
same('succeeded',$stage->fresh()->state,'The profile is proven before association');
Cache::flush();$GLOBALS['nsOperation']=$op->id;
// A new worker/transport reconstructs the durable link without recreating the profile.
$driver=new Management($binding,$transport->exchange());$driver->useReservation($transport->reservation);
same('succeeded',$management->run($op->id,$driver,RequestOrigin::Background)->state,'The original request completes through its separately sealed association');
same(['contactAdd','contactDomainAssociate'],array_values(array_filter($GLOBALS['nsPhysical'],static fn($c)=>AdmittedTransport::feature($c)!=='catalogue')),'Only two admitted mutations');
same($usage['used']+9,nsUsage($binding)['used'],'Every physical read and each separate mutation counts once');
same($usage['spend_micros'],nsUsage($binding)['spend_micros'],'Contact changes authorize no monetary spend');
same($before,$money(),'No invoice, credit, payment or queue mutation');same($purchase,$original->fresh()->attempt,'The acquisition seal is never borrowed');
$physical=$GLOBALS['nsPhysical'];$management->run($op->id,$driver,RequestOrigin::Background);
same($physical,$GLOBALS['nsPhysical'],'Completed sequence has no physical replay');
$nativeSuccessful=true;echo "Admitted contact sequence with separate sealed grants passed.\n";
