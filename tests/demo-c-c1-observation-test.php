<?php
// Complete native C4 observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/demo-c-c1-observation.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,HoldingObservationWork,ObservationProjection,ObservationRecords,ObservationSchema,OwnerRecords};
// Enom CAA stays held; staff get the specific cause and registrar recovery step.
// Sync now holds the family while the first scheduled read is still queued: staff see the hold at once, then that read gives its cause.
HoldingObservationWork::queueInitial($d->fresh(),$clock->now());
$state->dns[]=$caa;$tick();
same('refreshed',$observer->run($d->id,readRecords:true),'Legacy Enom CAA read holds only records');
same(['caa_unsupported','dns'],$hold(),'Legacy Enom CAA names its reason and family');
expect(!isset(OwnerRecords::forDomain($d->fresh())['dns']),'CAA keeps the DNS editor off');
Auth::login(staffUser([Staff::VIEW]));
same('DNS records held while the first registrar check runs.',DomainFacts::recordHold($d->fresh()),'A hold before the first scheduled read shows at once');
same(['DNS records held while the first registrar check runs.',null],[DomainFacts::notice($d->fresh())['title']??null,DomainFacts::notice($d->fresh())['body']??null],'The page notice shows the hold with no step while the read is queued');
// Current Processing gates suppress a promised retry before a worker records records_disabled.
$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->sole();
\Paymenter\Extensions\Servers\Domains\Models\Account::whereKey($account->id)->update(['processing_enabled'=>false]);
same(null,HoldingObservationWork::nextRead($d->fresh()),'A newly closed record gate promises no scheduled record retry');
\Paymenter\Extensions\Servers\Domains\Models\Account::whereKey($account->id)->update(['processing_enabled'=>true]);
// After the first read, a hold waiting on the daily read or a retry also shows at once, with that read's time in the site clock.
// A closed record gate reads no records, so it promises no read.
$siteClock=$clock->instant;config(['domains.time_zone'=>'America/Los_Angeles']);
$waits=[['refreshed',0,'2030-01-15T18:30:00Z','DNS records held until the next registrar check at Jan 15, 2030, 10:30 AM PST.'],
    ['read_failed',1,'2030-07-15T18:30:00Z','DNS records held until the registrar check retries at Jul 15, 2030, 11:30 AM PDT.'],
    ['refreshed',0,'2020-01-15T18:30:00Z','DNS records held until the next registrar check runs.'],
    ['records_disabled',0,'2030-01-15T18:30:00Z',null]];
foreach ($waits as [$code,$tries,$due,$text]) {
    HoldingObservationWork::current($d->fresh())->update(['tries'=>$tries,'safe_code'=>$code,'due_at'=>(new DateTimeImmutable($due))->getTimestamp()]);
    same($text,DomainFacts::recordHold($d->fresh()),'A held family waiting on '.$code.' due '.$due.' shows its wait');
    if ($text!==null) { same([$text,null],[DomainFacts::notice($d->fresh())['title']??null,DomainFacts::notice($d->fresh())['body']??null],'The page notice shows the '.$code.' wait with no step'); }
}
$clock->instant=$siteClock;config(['domains.time_zone'=>null]);
// The first scheduled read stops on the held family, as its finish records it.
same(1,HoldingObservationWork::current($d->fresh())->update(['tries'=>1,'safe_code'=>'records_incomplete']),'The queued first read is the current work');
same("Sync stopped: this domain has CAA records, which can't be read from Enom here. Other domain facts were saved. Manage this domain's DNS at Enom.",DomainFacts::recordHold($d->fresh()),'CAA hold names the read limitation and where staff can manage DNS');
array_pop($state->dns);$tick();$observer->run($d->id,readRecords:true);
expect(!ObservationRecords::held($d->fresh()),'Removing CAA clears the legacy hold');

$nativeSuccessful=true;echo "C1 legacy fallback and fail-closed CAA hold passed.\n";
