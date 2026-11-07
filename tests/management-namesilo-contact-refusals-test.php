<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\ContactProfile;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ManagementProfiles;
foreach (['default','mismatch','contactAdd'] as $fault) {
    [$d,$driver,$original]=$holding('profile-'.$fault); $input=['role'=>'registrant','contact'=>$original->payload['contacts']['registrant']];
    $ms->fault=$fault; $op=$submit($d,$driver,'contact_profile',$input);
    same('uncertain',$management->run($op->id,$driver)->state,'Missing nondefault exact profile proof stays unresolved');
    same(['contactAdd'],array_column($writes(),0),'No association or profile update after invalid creation proof');
    same($op->id,$submit($d,$driver,'contacts',$input)->id,'An unresolved contact change retains its request instead of creating an association');
    $management->run($op->id,$driver); same(1,count($writes()),'Unknown stage never recreates profile');
}
[$d,$driver,$original]=$holding('registry-revoked'); $input=['role'=>'all','contact'=>$original->payload['contacts']['registrant']];
$op=$submit($d,$driver,'contact_profile',$input);
// Revoke the current observed schema; the retained legacy column is no longer the management authority.
$facts=fixtureOfferFacts($binding)->endings;$facts[$d->tld]['registry_fields']=[['name'=>'unaccepted']];
fixtureObserveOfferFacts($binding,$facts);
$observed=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::facts($tlds[1]->fresh()->catalogue_facts);
same([['name'=>'unaccepted']],$observed->registryFields,'The active provider facts contain the newly unsupported schema');
expect($observed->fresh($clock->now())&&\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\TestCatalogueFacts::current($binding,$d->tld,$observed,$clock),'Fresh exact TEST protocol evidence isolates the registry-schema refusal');
same('held',$management->run($op->id,$driver)->state,'Unaccepted registry schema refuses profile creation'); same([],$writes(),'Registry gate emits no write');
$nativeSuccessful=true; echo "management-namesilo-contact-refusals-test.php passed\n";
