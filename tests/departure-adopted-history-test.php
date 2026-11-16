<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic transport only.
declare(strict_types=1);
require __DIR__.'/Support/adopted-renewal.php';
require_once __DIR__.'/Support/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,HoldingDeparture,HoldingLocks,MoneyOutcome,ObservationRecords,StaffHoldingReview};
extract(adoptedRenewalFixture(retainedHistory: true));
$GLOBALS['clock']=$clock;staffPanel();
$history=$domain->operations()->where('action','register')->sole();
same(['rejected',$domain->authority_generation-1],[$history->state,$history->authority_generation],'The adopted holding keeps its proved unsent acquisition history');
// A fresh definite absence read puts the adopted holding under departure review.
$domain->update(['observation'=>[...($domain->observation??[]),'renewal_ownership'=>['identity'=>ObservationRecords::identity($domain),'owned'=>false,'at'=>$clock->now()->format(DATE_ATOM)]]]);
same(true,DepartureChecks::suspected($domain->fresh()),'The adopted holding is under review');

$preview=HoldingDeparture::preview($domain->fresh(),$clock->now()->getTimestamp());
same(null,$preview['block'],'Retained adoption history does not block confirmation');
// The same history changed after adoption is not proof and still blocks.
$bytes=$history->getRawOriginal('attempt');
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$history->id)->update(['attempt'=>encrypt(json_encode(['seal'=>'sealed','send_state'=>'possibly_sent']),false)]);
expect(HoldingDeparture::preview($domain->fresh(),$clock->now()->getTimestamp())['block']!==null,'Changed acquisition history still blocks');
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$history->id)->update(['attempt'=>$bytes]);
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$domain->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($domain->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$domain->id,$ticket,'other_registrar','Checked the registrar account.');
same([MoneyOutcome::Applied,null],[$result->outcome,$result->error?->getMessage()],'Confirmation completes for the adopted holding');
same(['departed','rejected'],[$domain->fresh()->status,$history->fresh()->state],'The holding departs and its history stays as it was');

echo "Proved adoption history does not block a departure.\n";
$nativeSuccessful=true;
