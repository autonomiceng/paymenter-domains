<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native transfer staff boundaries.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Staff\Release\HeldRelease;
require __DIR__.'/Support/staff-transfer.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations,StaffOperationWrites,TransferInputs};
// A real paid request can be held while still awaiting its owner's code. The raw malformed code, paid-at and deadline rows
// run through the same offer, page and writer checks in Support/staff-release-states.php (staff-transfer-release-parity-test.php).
$row=$newTransfer(placeholderComFixtureName('staff-transfer-missing-code-placeholder.com'),0,true,false);
same('awaiting_code',$row->state,'Paid transfer genuinely awaits owner code');
$run($row,'hold');$row=$row->fresh();
$before=$row->getRawOriginal();commerceReject(fn()=>$run($row,'release'));
same($before,$row->fresh()->getRawOriginal(),'Invalid transfer release leaves evidence intact');
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release');
expect((RequestFacts::notice($row)['action']??null)!=='release','Invalid transfer notice has no release');
same(false,OperationActions::relevant('release',$row),'Invalid transfer page has no release');
same(false,HeldRelease::offered($row,$clock->now()),'Transfer release eligibility requires saved inputs');
// A page that offered Release hold must not keep offering it once the deadline passes during an action (RequestReads::renew).
$row=$newTransfer(placeholderComFixtureName('staff-transfer-cutoff-placeholder.com'),0,true,true);$run($row,'hold');$row=$row->fresh();
$page=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionVisible('release')->instance();
same(true,HeldRelease::offered($page->getRecord(),$clock->now()),'Valid held transfer is offered release');
$clock->instant=TransferInputs::deadline($row,$clock->now());
(fn()=>$this->reloadRequest())->call($page);
same(false,HeldRelease::offered($page->getRecord(),$clock->now()),'Render after an action rereads the release offer at the deadline');
$clock->instant=$now;
same([], $state->calls,'Staff never sends transfer or registrar refund');same(0,$state->reads,'Pages and local actions never read registrar');
echo "Native malformed transfer input and deadline refusals passed.\n";$nativeSuccessful=true;
