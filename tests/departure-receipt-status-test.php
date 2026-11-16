<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic stale discovery read only.
declare(strict_types=1);
require __DIR__.'/transfer-out-not-found-test.php';
require_once __DIR__.'/Support/staff-panel.php';
use App\Models\Credit;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureProof,HoldingDeparture,HoldingLocks,MoneyOutcome,PricingRule,Refunds,RenewalCycles,StaffHoldingReview};

$nativeSuccessful=false;staffPanel();
$renewal=$d->operations()->where('action','renew')->where('invoice_id',$invoice->id)->sole();
$prior=$d->fresh()->getAttributes();
$staff=staffUser([Staff::VIEW,'admin.domains.manage',HoldingDeparture::PERMISSION]);
$ticket=StaffHoldingReview::ticket((int)$staff->id,(int)$d->id,HoldingDeparture::ACTION,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
$result=(new HoldingDeparture($clock))->confirm($staff,(int)$d->id,$ticket,'other_registrar','Checked the registrar account.');
same(MoneyOutcome::Applied,$result->outcome,'The renewal invoice was cancelled by departure');
// Model a discovery read completed before confirmation while the invoice-lock wait completes afterward.
// Only the first holding read sees the old snapshot; the later locked read sees the departed row.
$stale=true;$injected=false;
Domain::retrieved(static function(Domain $row)use(&$stale,&$injected,$prior,$d):void{
    if($stale&&(int)$row->id===(int)$d->id){$stale=false;$injected=true;$row->setRawAttributes($prior,true);}
});
$error=null;try{RenewalCycles::beforePayment((int)$invoice->id,$clock);}catch(Throwable $e){$error=$e;}
$stale=false;
same(true,$injected,'The receipt admission read saw the pre-departure snapshot');
same(null,$error?->getMessage(),'A receipt waiting behind confirmation uses current departed authority');
$balance=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$d->user_id)->where('currency_code','USD')->value('amount')??'0.00'));
$before=$balance();
App\Helpers\ExtensionHelper::addPayment($invoice->fresh(),null,'1.20','0.00','departed-after-lock');
(new Refunds($clock))->creditRejected((int)$renewal->id);
same($before+120,$balance(),'The admitted receipt returns to the departed owner once');
same(['departed',null,true],[$d->fresh()->status,$d->fresh()->reservation_name,DepartureProof::verified($d->fresh())],'Receipt admission restores no holding authority');
echo "A receipt admitted after confirmation uses the current departed holding.\n";
$nativeSuccessful=true;
