<?php
// Synthetic offline transfer observation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/observation-namesilo.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationCandidates,ObservationSchema,ObservationWork};
// Separate candidate cursors are fair, persist across calls and grant no financial mutation.
same([$enom],array_keys(ObservationCandidates::next('refund',1)),'First candidate account');same([$ns],array_keys(ObservationCandidates::next('refund',1)),'Candidate fairness independent of observation backlog');
$clock->instant=$now;Carbon\Carbon::setTestNow($now);Carbon\CarbonImmutable::setTestNow($now);$missing=$newTransfer(placeholderComFixtureName('missing-observation-code-placeholder.com'),1,true,false);$work->tick(2);
$bindings->setProcessing($ns,false);$selected=ObservationCandidates::next('timeout',2);expect(in_array($missing->id,$selected[$ns],true),'Missing-code candidate selected even without processing');
same('awaiting_code',$missing->fresh()->state,'Selection does not timeout or credit');
$bindings->setProcessing($ns, true, syntheticActivation());
// Resume advances the catalogue revision; publish locally before creating the follow-up transfer.
activeFixtureRepublish($bindings, Paymenter\Extensions\Servers\Domains\Models\Tld::where(['server_id'=>$ns,'tld'=>'com'])->sole());
[$active,$unused,$acquired]=$holding('followup');same('pending',$active->observation['followup']['state'],'Transfer completion seeds durable follow-up');
$bindings->setProcessing($ns,false);$clock->instant=$clock->now()->modify('+1 minute');same('refreshed',$observer->run($active->id),'Paused old account refreshes');
same(1,$active->fresh()->observation['followup']['reads'],'Follow-up advances facts only');
// Explicit verifier-enrolled retained holdings are covered separately in observation-namesilo-legacy-test.
$nativeSuccessful=true;echo "NameSilo independent candidate cursors, missing code and durable follow-up passed.\n";
