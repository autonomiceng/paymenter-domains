<?php
// Complete native C4 observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/demo-c-c1-observation.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,OwnerRecords};
// Enom CAA stays held (fail closed). Staff copy remains owned by the words lane.
$state->dns[]=$caa;$tick();
same('refreshed',$observer->run($d->id,readRecords:true),'Legacy Enom CAA read holds only records');
same(['caa_unsupported','dns'],$hold(),'Legacy Enom CAA names its reason and family');
expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'CAA keeps the DNS editor off');
Auth::login(staffUser([Staff::VIEW]));
same('Sync stopped: DNS record check failed.',DomainFacts::recordHold($d->fresh()),'CAA hold retains the current staff fallback pending the words lane');
array_pop($state->dns);$tick();$observer->run($d->id,readRecords:true);
expect(!ObservationRecords::held($d->fresh()),'Removing CAA clears the legacy hold');

$nativeSuccessful=true;echo "C1 legacy fallback and fail-closed CAA hold passed.\n";
