<?php
// Menu counts: each one is the page's own "needs a person" filter, and zero shows nothing. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, OperationResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;
staffPanel(); [$held, $done, $long]=staffData(); Auth::setUser(staffUser([Staff::VIEW]));
$badges=fn()=>[DomainResource::getNavigationBadge(), OperationResource::getNavigationBadge()];
$op=fn($domain)=>Operation::where('domain_id',$domain->id);

// Registrar requests: On hold and Under review wait on staff. The fixture has two held requests and no alerts.
same([null,'2'],$badges(),'Two requests on hold, no domain alerts');
same(['warning','warning'],[DomainResource::getNavigationBadgeColor(),OperationResource::getNavigationBadgeColor()],'Both counts ask a person to act');
$op($long)->update(['state'=>'uncertain']);
same('2',OperationResource::getNavigationBadge(),'Under review counts too');
// Awaiting payment waits on the customer, and a request left behind by an older registration is not on the list.
$op($long)->update(['state'=>'awaiting_payment']);$op($held)->update(['authority_generation'=>2]);
same(null,OperationResource::getNavigationBadge(),'Nothing to act on: no count');

// Domains: domains with an open alert, the list's Open alerts filter. Two alerts on one domain count it once.
ObligationAlerts::record($done,'unknown_expiry',time());ObligationAlerts::record($done,'missing_obligation',time());
same('1',DomainResource::getNavigationBadge(),'One domain with open alerts');
DB::table('domains_obligation_alerts')->where('domain_id',$done->id)->update(['resolved_at'=>time()]);
same(null,DomainResource::getNavigationBadge(),'Resolved alerts leave the count');

// Without staff access there is no count to read.
Auth::setUser(staffUser([]));
same([null,null],$badges(),'No count without access');
$nativeSuccessful=true;echo "Menu counts match their page filters.\n";
