<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native transfer staff boundaries.
declare(strict_types=1);
require __DIR__.'/Support/staff-transfer.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations,StaffOperationWrites,TransferInputs};
// A real paid request can be held while still awaiting its owner's code.
// Raw malformed rows cover retained input evidence that the current input boundary would refuse.
foreach(['missing_code','invalid_code','missing_paid_at','invalid_paid_at','expired_deadline'] as $fault){
    $row=$newTransfer(placeholderComFixtureName('staff-transfer-'.str_replace('_','-',$fault).'-placeholder.com'),0,true,$fault!=='missing_code');
    if($fault==='missing_code'){same('awaiting_code',$row->state,'Paid transfer genuinely awaits owner code');}
    $run($row,'hold');$row=$row->fresh();
    if(in_array($fault,['invalid_code','missing_paid_at','invalid_paid_at'],true)){
        $payload=$row->payload;$policy=$row->policy;
        if($fault==='invalid_code'){$payload['epp']="\n";}
        elseif($fault==='missing_paid_at'){unset($policy['paid_at']);}
        else{$policy['paid_at']='not-a-time';}
        $row->setAttribute('payload',$payload);$row->setAttribute('policy',$policy);
        Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$row->id)->update(array_intersect_key($row->getAttributes(),array_flip(['payload','policy'])));
        $row=$row->fresh();
    }
    if($fault==='expired_deadline'){$clock->instant=TransferInputs::deadline($row,$clock->now());}
    $before=$row->getRawOriginal();commerceReject(fn()=>$run($row,'release'));
    same($before,$row->fresh()->getRawOriginal(),'Invalid transfer release leaves evidence intact: '.$fault);
    Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$row->id])->assertActionHidden('release')->assertDontSee('Release hold');
    expect((RequestFacts::notice($row)['action']??null)!=='release','Invalid transfer notice has no release: '.$fault);
    same(false,OperationActions::relevant('release',$row),'Invalid transfer page has no release: '.$fault);
    same(false,StaffOperationWrites::canRelease($row,$clock->now()),'Transfer release eligibility requires saved inputs: '.$fault);
    $clock->instant=$now;
}
same([], $state->calls,'Staff never sends transfer or registrar refund');same(0,$state->reads,'Pages and local actions never read registrar');
echo "Native malformed transfer input and deadline refusals passed.\n";$nativeSuccessful=true;
