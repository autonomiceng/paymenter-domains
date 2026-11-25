<?php
// Complete native C4 observation matrix; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/demo-c-c1-observation.php';
require_once __DIR__.'/Support/fixture-cases.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\ObservationReader;
use Paymenter\Extensions\Servers\Domains\Services\{DomainObservation,HoldingObservationWork,ObservationProjection,ObservationRecords,OwnerRecords};
// The legacy CAA hold and its scheduled waits, the wired CAA, glue and one-clock recovery, and the wired family
// failures with complete resume run as cases on the one observed holding. The reset restores the observation rows,
// scheduled work, fake registrar state, config, login and the clocks.
// Pin each case to the prepared read and Carbon clocks explicitly, whatever a reset restores.
$pinned=[$clock->now(),Carbon\Carbon::getTestNow(),Carbon\CarbonImmutable::getTestNow()];
$pin=static function()use($clock,$pinned):void{[$clock->instant,$native,$immutable]=$pinned;Carbon\Carbon::setTestNow($native);Carbon\CarbonImmutable::setTestNow($immutable);};
nativeFixtureCases([
    'legacy-caa' => static function () use ($pin, $d, $clock, $state, $observation, $observer, $budget, $ports, $readBindings, $exchange, $caa, $hold, $tick): void {
        $pin();
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
    },
    'wired-clock' => static function () use ($pin, $d, $clock, $state, $observation, $observer, $budget, $ports, $readBindings, $exchange, $caa, $hold, $tick): void {
        $pin();
        // The runtime resolver drives the dedicated reader through DomainObservation::run, with no registrar writes.
        $wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
        $writes=static fn()=>array_values(array_filter(array_column($state->calls,0),static fn($c)=>!str_starts_with($c,'Get')&&$c!=='CheckNSStatus'));
        $state->calls=[];
        $state->dns[]=$caa;$tick();
        $wired->run($d->id,readRecords:true);
        same(['caa_unsupported','dns'],$hold(),'Wired Enom CAA keeps its own reason');
        array_pop($state->dns);
        // A wired Enom glue-only failure keeps its own reason.
        $observation->nameservers=$state->ns=['ns1.'.$d->domain,'ns2.example.net'];$state->glue['ns1.'.$d->domain]='8.8.4.4';
        $state->fault='CheckNSStatus';$tick();$wired->run($d->id,readRecords:true);
        same(['glue_unavailable','glue'],$hold(),'Wired Enom glue-only failure names glue');
        $state->fault='';
        // Save and read-back share the observer's clock, even when the app clock lags behind it.
        $appClock=app()->bound(Clock::class)?app(Clock::class):$clock;
        app()->instance(Clock::class,new class($clock) implements Clock{public function __construct(private Clock $c){} public function now():DateTimeImmutable{return $this->c->now()->modify('-1 hour');}});
        $tick();$wired->run($d->id,readRecords:true);
        app()->instance(Clock::class,$appClock);
        expect(!ObservationRecords::held($d->fresh()),'A lagging app clock does not hold a fresh wired refresh');
        same([],$writes(),'Wired observation cycle and resume send no registrar writes');
    },
    'wired-families' => static function () use ($pin, $d, $clock, $state, $observation, $observer, $budget, $ports, $readBindings, $exchange, $caa, $hold, $tick): void {
        $pin();
        Auth::login(staffUser([Staff::VIEW]));
        // The runtime resolver drives the dedicated reader through DomainObservation::run, with no registrar writes.
        $wired=new DomainObservation($readBindings,$clock,$budget,$ports,null,static fn($binding)=>new ObservationReader($binding,$exchange));
        $writes=static fn()=>array_values(array_filter(array_column($state->calls,0),static fn($c)=>!str_starts_with($c,'Get')&&$c!=='CheckNSStatus'));
        $state->calls=[];$state->fault='GetDomainSRVHosts';$tick();
        $wired->run($d->id,readRecords:true);
        $r=$d->fresh()->observation;same(1,$r['records']['format'],'Wired refresh writes versioned records');
        same(array_fill_keys(['dns','srv','ds','glue'],'supported'),$r['records']['expected_families'],'Wired refresh writes all four expectations');
        same(['records_unavailable','srv'],$hold(),'Failed family is named on the hold');
        $status=ObservationProjection::recordStatus($d->fresh(),$clock->now());same([true,'srv'],[$status['resume'],$status['failed_family']],'Staff status offers resume for the failed family');
        same("Sync stopped: couldn't read SRV records. Other domain facts were saved. Use Sync now to try again.",DomainFacts::recordHold($d->fresh()),'Staff copy names the failed record family');
        // A failed Enom contacts read names contacts, not DNS records.
        $state->fault='GetContacts';$tick();$wired->run($d->id,readRecords:true);
        same(['records_unavailable','contacts'],$hold(),'Failed contacts read is named on the hold');
        same("Sync stopped: couldn't read contacts. Other domain facts were saved. Use Sync now to try again.",DomainFacts::recordHold($d->fresh()),'Staff copy names the failed contacts read');
        $state->fault='';$tick();
        $observation->calls=[];$recordStart=count($state->calls);
        $wired->run($d->id,readRecords:true);
        $commands=[...array_column($observation->calls,'value'),...array_column(array_slice($state->calls,$recordStart),0)];
        sort($commands);
        same(['GetContacts','GetDNS','GetDnsSec','GetDomainInfo','GetDomainSRVHosts','GetHosts','GetRegLock','GetRenew','GetWPPSInfo'],
            $commands,'One Enom record observation sent '.implode(', ',$commands));
        expect(!ObservationRecords::held($d->fresh()),'Complete wired refresh clears the hold');
        same($clock->now()->format(DATE_ATOM),ObservationProjection::recordStatus($d->fresh(),$clock->now())['last_complete_at'],'Complete wired refresh advances last complete time');
        same([],$writes(),'Wired family failures and resume send no registrar writes');
    },
], compact('clock','state','observation','observer','budget','ports','management','guard','user','product','bindings','readBindings'));
$nativeSuccessful=true;echo "C1 legacy fallback and CAA hold, wired CAA, glue-only failure, one-clock recovery, family failures and complete resume passed.\n";
