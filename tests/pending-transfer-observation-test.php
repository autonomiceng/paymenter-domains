<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native transfer with synthetic provider transports only.
declare(strict_types=1);
$fixtureTransferRegistrars=['enom'];
require __DIR__.'/Support/transfer-completion-enom-fixture.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationRecords};
$op=$prepare(placeholderComFixtureName('pending-observation-placeholder.com'));$d=$op->domain()->firstOrFail();
$observe->fault='pending';$observe->unowned=true;$before=$op->fresh()->getRawOriginal();$writes=$state->calls;
$expiry=$d->provider_expires_at;$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock));
same('healthy_pending',$observer->run($d->id),'An unowned pending transfer remains a normal pending observation');
$fresh=$d->fresh();same('transfer_pending',$fresh->status,'A pending read cannot activate the transfer');
expect(!isset($fresh->observation['record_failure'])&&!ObservationRecords::held($fresh),'Normal pending ownership creates no permanent record hold');
same($before,$op->fresh()->getRawOriginal(),'Pending observation changes no operation, funding or sealed evidence');
same($expiry,$fresh->provider_expires_at,'Pending observation cannot invent a confirmed expiry');
same($writes,$state->calls,'Observation cannot resend the transfer');
$observe->unowned=false;$observe->fault='';same('succeeded',$reconcile->run($op->id)->state,'Original confirmed transfer still completes normally');
$observe->unowned=true;
same('domain_not_in_account',$observer->run($d->id),'The same unowned snapshot still stops an active domain');
same('domain_not_in_account',$d->fresh()->observation['record_failure']['code'],'Active ownership failure retains its exact cause');
expect(ObservationRecords::held($d->fresh()),'Active ownership loss retains the record hold');
echo "Pending transfer ownership and active absence remain distinct\n";$nativeSuccessful=true;
