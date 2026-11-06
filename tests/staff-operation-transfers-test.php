<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native transfer staff boundaries.
declare(strict_types=1);
require __DIR__.'/Support/staff-transfer.php';
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations,StaffOperationWrites,TransferInputs};
// A ready NameSilo transfer needs its own setup checks, without an Enom-only refusal.
Illuminate\Support\Facades\Auth::setUser($staff);
$ready=$newTransfer(placeholderComFixtureName('staff-ready-namesilo-placeholder.com'),1);
Illuminate\Support\Facades\DB::table('domains_accounts')->where('server_id',$ready->server_id)->update(['processing_enabled'=>false]);
(new \Paymenter\Extensions\Servers\Domains\Services\OperationClaims($bindings,$clock))->claim((int)$ready->id);
$ready=$ready->fresh();same('processing',$ready->safe_code,'Orders off holds the paid saved-code transfer');
same('Ready to send. Orders are off for this account.',RequestFacts::readyMessage($ready),'Ready NameSilo transfer names only the Orders switch');
$payload=$ready->getRawOriginal('payload');
\Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$ready->id)->update(['payload'=>'unreadable']);
\Illuminate\Support\Facades\Auth::setUser($staff);
$notice=\Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::notice($ready->domain->fresh());
expect(str_contains($notice['title'],'not sent'),'Unreadable ready payload falls back to the held domain notice');
same('Check request #'.$ready->id,$notice['action']['label'],'Unreadable ready payload retains the request recovery link');
\Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$ready->id)->update(['payload'=>$payload]);

Illuminate\Support\Facades\DB::table('domains_accounts')->where('server_id',$ready->server_id)->update(['processing_enabled'=>true]);
$op=$newTransfer(placeholderComFixtureName('staff-saved-transfer-placeholder.com'));$op->update(['state'=>'held','safe_code'=>'staff_review_required']);
// Obvious state/action refusals do not load a holding snapshot. The locked writer still refuses them.
foreach([['queued','register'],['rejected','transfer'],['succeeded','renew'],['held','contacts']] as [$savedState,$savedAction]){
    $candidate=$op->fresh();$candidate->state=$savedState;$candidate->action=$savedAction;
    Illuminate\Support\Facades\DB::enableQueryLog();Illuminate\Support\Facades\DB::flushQueryLog();
    $allowed=StaffOperationWrites::canRelease($candidate,$clock->now());$reads=Illuminate\Support\Facades\DB::getQueryLog();Illuminate\Support\Facades\DB::disableQueryLog();
    same(false,$allowed,'Non-held or unsupported request cannot be released');same([],$reads,'State/action prefilter avoids holding-fact reads');
    commerceReject(fn()=>StaffOperationWrites::requireRelease($candidate,[],$clock->now()));
}
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('release')->assertNotified('Hold released');
same('queued',$op->fresh()->state,'Saved-code held transfer may queue only before cutoff');
$run($op,'hold');$clock->instant=$now->modify('+30 days');commerceReject(fn()=>$run($op,'release'));
Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$op->id])->callAction('refund',['reason'=>'Close proven unsent saved-code transfer'])->assertNotified('Request cancelled');
same('account_credit',$op->fresh()->funding['refund']['to'],'Exact saved-code credit');same('definitely_unsent',$op->fresh()->attempt['send_state'],'Only virgin evidence creates local negative proof');
$clock->instant=$now;
foreach(['transfer_not_registered','staff_review_required']as$reason){
    $unpaid=$reason==='staff_review_required';$row=$newTransfer(placeholderComFixtureName('staff-transfer-'.($unpaid?'unpaid':'404').'-placeholder.com'),0,!$unpaid);
    $row->update(['state'=>'held','safe_code'=>$reason]);$before=[$row->fresh()->state,$row->fresh()->attempt,$row->fresh()->funding,$row->domain->fresh()->reservation_name];
    foreach(['release','refund','credit','disposition']as$action){commerceReject(fn()=>$run($row,$action));}
    same($before,[$row->fresh()->state,$row->fresh()->attempt,$row->fresh()->funding,$row->domain->fresh()->reservation_name],'404 without fresh proof and never-paid holds retained');
}
same([], $state->calls,'Staff never sends transfer or registrar refund');same(0,$state->reads,'Pages and local actions never read registrar');
echo "Native saved-code, cutoff, missing fresh404 and never-paid refusals passed.\n";$nativeSuccessful=true;
