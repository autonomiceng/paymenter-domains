<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['namesilo'];
require __DIR__.'/Support/management-namesilo.php';
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Models\ContactProfile;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ManagementProfiles;
[$d,$driver,$original]=$holding('delayed-profile'); $input=['role'=>'tech','contact'=>$original->payload['contacts']['tech']];
$stage=$submit($d,$driver,'contact_profile',$input); same('succeeded',$management->run($stage->id,$driver)->state,'New isolated stage');
$ms->calls=[]; $op=$submit($d,$driver,'contacts',$input);
same('queued',$management->run($op->id,$driver)->state,'Creation write slot defers association'); same([],$ms->calls,'Busy slot performs no reads or writes');
$clock->instant=$clock->now()->modify('+181 seconds'); Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());
same('succeeded',$management->run($op->id,$driver)->state,'Delayed association uses fresh named profile proof after slot expires');
same(['getDomainInfo','contactList','contactDomainAssociate','getDomainInfo','contactList'],array_column($ms->calls,0),'Fresh exact profile read precedes delayed association');
$nativeSuccessful=true;echo "Native NameSilo management safety case passed.\n";
