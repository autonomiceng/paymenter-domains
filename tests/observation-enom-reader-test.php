<?php
// Full native observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/observation-records.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,ObservationWork,OwnerRecords,ReadDeferred};
// NS15 uses the native encrypted model and existing synthetic provider transport: a plain codec cannot prove owner authority.
[$d,$driver,$original]=$holding(placeholderComFixtureName('ns15-enom-placeholder.com'));
$b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->sole());
$ns15=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader($b,$exchange);
$ctx=fn()=>new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+250 seconds'),new RequestBudget(25),$clock,stopOnReadFailure:true);
$request=new ManagementRequest($b,'ns15-enom',$d->domain,'discovery',[],$d->provider_domain_id,domainId:$d->id);
$state->contacts=['registrant'=>['FirstName'=>'Synthetic']];
$discovered=$ns15->discover($request,$ctx());
same('partial',$discovered['settings']['contacts']['state'],'Missing Enom contact roles are partial');
ObservationRecords::save($d,$discovered,$clock->now(),$ns15->observationCapabilities());
$r=$d->fresh()->observation['records'];same(1,$r['format'],'Bounded refresh emits versioned records');
same('partial',$r['settings']['contacts']['state'],'Encrypted partial contacts survive');
$view=ObservationProjection::recordStatus($d->fresh(),$clock->now());
same('complete',$view['families']['dns']['state'],'Supported complete empty remains complete');
same('partial',$view['settings']['contacts']['state'],'Staff can identify failed contact family');
$state->contacts=array_fill_keys(['registrant','admin','tech','auxbilling'],['FirstName'=>'Synthetic','LastName'=>'Person','Address1'=>'1 Example Street','City'=>'Portland','PostalCode'=>'97201','Country'=>'US','EmailAddress'=>'synthetic@example.com','Phone'=>'+1.5035550123']);
$discovered=$ns15->discover($request,$ctx());
ObservationRecords::save($d->fresh(),$discovered,$clock->now(),$ns15->observationCapabilities());
same(false,ObservationRecords::incomplete($d->fresh()),'Successful bounded partial Enom glue is sufficient');
$good=$d->fresh()->observation['records'];
$state->fault='GetDomainSRVHosts';$state->calls=[];$clock->instant=$clock->now()->modify('+1 minute');
$partial=$ns15->discover($request,$ctx());
ObservationRecords::save($d->fresh(),$partial,$clock->now(),$ns15->observationCapabilities());
expect(ObservationRecords::incomplete($d->fresh()),'Failed expected Enom family needs resume');
same('complete',$d->fresh()->observation['records']['families']['dns']['state'],'Independent DNS remains complete');
same($good['at'],ObservationProjection::recordStatus($d->fresh(),$clock->now())['families']['srv']['last_good_at'],'Failed family retains last-good time');
expect(!in_array('GetDnsSec',array_column($state->calls,0),true),'Reader stops after first failed wire');
$state->fault='';
$future=$d->fresh()->observation;$future['records']['at']=$clock->now()->modify('+1 hour')->format(DATE_ATOM);$d->update(['observation'=>$future]);
same(null,ObservationRecords::current($d->fresh(),$clock->now()),'Future inventory grants no current authority');

$nativeSuccessful=true;echo "Versioned dedicated Enom records, partial contacts, last-good evidence and future refusal passed.\n";
