<?php
// Enom 444f31c request-gates/provider-disposition/transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/transfer-dispatch-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\{OperationActions,RequestFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Services\{BalanceRetries,OperationTerminalProof,Refunds,StaffOperationReview,StaffOperations};
staffPanel();app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);app()->instance(Refunds::class,new Refunds($clock));
$permissions=[Staff::VIEW,'admin.domains.manage','admin.domains.fund','admin.invoices.update'];foreach(array_keys(StaffOperationReview::ACTIONS) as $action){$permissions[]=StaffOperationReview::permission($action);}
$staff=staffUser($permissions);Illuminate\Support\Facades\Auth::setUser($staff);$staffOperations=new StaffOperations($clock);
// The same staff action the request page runs, with its real ticket.
$act=fn($op,$action)=>$staffOperations->run($staff,$op->domain_id,$op->id,$action,StaffOperationReview::ticket($staff->id,$op->domain_id,$op->id,$action,StaffOperationReview::stamp($op->fresh(),$op->domain->fresh()),$clock),'Reviewed current evidence');
$state->mode=''; $state->calls=[]; $op=$newTransfer(placeholderComFixtureName('no-paid-placeholder.com'),0,false); $makeTransfer(); $engine->execute($op->id); same([],$state->calls,'Unpaid transfer never writes');
$op=$newTransfer(placeholderComFixtureName('no-code-placeholder.com'),0,true,false); $makeTransfer(); same('awaiting_code',$engine->execute($op->id)->state,'Missing code retains editable input state'); same([],$state->calls,'Missing code never writes');
$slot='domains-write-slot:'.$binding->fingerprint; same(null,Illuminate\Support\Facades\Cache::get($slot),'Missing code reserves no slot');
foreach ([true,false] as $enabled) {
    $makeTransfer(0,new Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(transfer:$enabled)); Illuminate\Support\Facades\Cache::put($slot,'other-dispatch',25);
    same('awaiting_code',$engine->execute($op->id)->state,'Busy slot or disabled feature cannot trap missing code');
    same('other-dispatch',Illuminate\Support\Facades\Cache::get($slot),'Other dispatch slot untouched');
    same('awaiting_code',$claims->holdUnclaimed($op->id,'write_rate_limited')->state,'Defensive unclaimed hold preserves editable input');
}
$request=Illuminate\Http\Request::create('/internal-transfer-input','POST'); $request->request->set('transfer_code',str_repeat('x',12)); $request->setUserResolver(fn()=>$user); $inputs->submitCode($request,$op->id);
expect(Paymenter\Extensions\Servers\Domains\Services\TransferInputs::validCode($op->fresh()->payload['epp']??null),'Later owner input remains editable');
$op=$newTransfer(placeholderComFixtureName('disabled-transfer-placeholder.com')); $makeTransfer(0,new Paymenter\Extensions\Servers\Domains\Services\WriteFeatures(registration:true));
same('held',$engine->execute($op->id)->state,'Default off transfer gate'); same([],$state->calls,'Registration permission grants no transfer permission');
foreach (['premium','unknown','funds','changed','reverse','rules','profile','rdap404'] as $fault) {
    $i=$fault==='profile'?1:0; $op=$newTransfer(placeholderComFixtureName('refuse-'.$fault.'-placeholder.com'),$i); $makeTransfer($i); $state->calls=[]; $state->reads=0;
    $state->premium=$fault==='premium'?true:($fault==='unknown'?null:false); $state->funds=$fault==='funds'?'1.00':'100.00'; $state->mode=$fault==='profile'?'default':'';
    $state->rdap404=$fault==='rdap404';
    $state->during=static function($read)use($fault,$op,$state){
        if ($read!==2) { return; }
        if ($fault==='changed') { $state->available=false; }
        if ($fault==='reverse') { App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>'-0.01','fee'=>'0.00','status'=>'succeeded']); }
        if ($fault==='rules') { App\Models\Server::find($op->server_id)->settings()->where('key','domains_transfer_endings')->update(['value'=>'net']); }
    };
    $r=$engine->execute($op->id); same('held',$r->state,'Changed admission held before seal'); same('absent',$r->attempt['seal'],'No send seal');
    $cause=['premium'=>'premium_name','unknown'=>'registrar_read_failed','funds'=>'registrar_balance_insufficient','changed'=>'name_unavailable'][$fault]??null;
    if($cause){same($cause,$r->safe_code,'Transfer refusal keeps its cause: '.$fault);}
    same([],array_values(array_intersect($state->calls,['TP_CreateOrder','transferDomain'])),'Refused admission sends nothing');
    if ($fault==='funds') { same('registrar_balance_insufficient',$r->safe_code,'A transfer shortfall has its own honest hold reason'); }
    if ($fault==='rdap404') { same('transfer_not_registered',$r->safe_code,'Fresh domain-endpoint404 retains distinct unsent hold'); commerceReject(fn()=>OperationTerminalProof::encode($claims,$r->id)); commerceReject(fn()=>$act($r,'refund')); same('held',$r->fresh()->state,'A registry 404 still needs its fresh disposition'); }
    $state->rdap404=false; $state->during=null; $state->premium=false; $state->funds='100.00'; $state->available=true; $state->mode='';
    App\Models\Server::find($op->server_id)->settings()->where('key','domains_transfer_endings')->update(['value'=>'com']);
    if ($fault==='changed') {
        Illuminate\Support\Facades\Auth::setUser($staff);same('refund',RequestFacts::notice($r)['action'],'A final refusal offers Cancel and add credit');
        $invoiceBefore = App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal();
        $itemsBefore = Illuminate\Support\Facades\DB::table('invoice_items')->orderBy('id')->get()->toJson();
        $transactionsBefore = Illuminate\Support\Facades\DB::table('invoice_transactions')->orderBy('id')->get()->toJson();
        same(0, App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->count(), 'No pre-existing credit hides a missing refund');
        $act($r,'refund');$terminal=$r->fresh();same('rejected',$terminal->state,'Cancel closes a claimed, unsealed, unsent transfer');
        expect(OperationTerminalProof::safe($terminal,$terminal->domain),'Cancel ends on the locked terminal proof');
        same($r->attempt['claim_key'],$terminal->attempt['claim_key'],'Cancel keeps the original claim audit');
        $credit = App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->sole();
        same(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($op->amount), \Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$credit->amount), 'One exact retained account credit');
        same('paid', App\Models\Invoice::findOrFail($op->invoice_id)->status, 'Refund retains paid invoice status');
        same(App\Enums\InvoiceTransactionStatus::Succeeded, App\Models\InvoiceTransaction::where('invoice_id',$op->invoice_id)->sole()->status, 'Original successful payment remains');
        same($invoiceBefore, App\Models\Invoice::findOrFail($op->invoice_id)->getRawOriginal(), 'Refund retains invoice bytes');
        same($itemsBefore, Illuminate\Support\Facades\DB::table('invoice_items')->orderBy('id')->get()->toJson(), 'Refund retains issued item bytes');
        same($transactionsBefore, Illuminate\Support\Facades\DB::table('invoice_transactions')->orderBy('id')->get()->toJson(), 'Refund retains transaction bytes');
        $afterCredit = nativeFinancialSnapshot($r);
        Illuminate\Support\Facades\DB::beginTransaction();
        try {
            Illuminate\Support\Facades\DB::table('invoice_items')->where('id',$op->invoice_item_id)->update(['description'=>'Synthetic changed line']);
            expect($afterCredit !== nativeFinancialSnapshot($r), 'Financial snapshot detects changed invoice items');
        } finally { Illuminate\Support\Facades\DB::rollBack(); }
        same($afterCredit, nativeFinancialSnapshot($r), 'Snapshot probe rolls back all item changes');
        same(null,$refunds->creditRejected($r->id),'Credit once');
        same($afterCredit, nativeFinancialSnapshot($r), 'Replay retains exact credit, invoice, item and transaction rows');
    }
}
// A local rate-limit deferral before the final refusal is not send evidence; Cancel still works.
$op=$newTransfer(placeholderComFixtureName('refuse-deferred-placeholder.com'));$makeTransfer();$state->calls=[];$state->reads=0;
same('queued',$claims->defer($claims->claim($op->id)[0])->state,'A local deferral requeues');
$state->premium=true;$r=$engine->execute($op->id);$state->premium=false;
same(['held','premium_name',1],[$r->state,$r->safe_code,count($r->attempt['capacity_deferrals'])],'A refusal after a deferral keeps its cause');
$act($r,'refund');same('rejected',$r->fresh()->state,'Cancel works after a local deferral');
expect(OperationTerminalProof::safe($r->fresh(),$r->domain->fresh()),'A deferral keeps the terminal proof');
$forged=$r->fresh();$forged->attempt=[...$forged->attempt,'capacity_deferrals'=>['not-a-time']];expect(!OperationTerminalProof::safe($forged,$forged->domain),'A malformed deferral fails the terminal proof');
same(1,App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->count(),'Credit stays on one row');
// No automatic retry here, so the failed read has to keep a Release that works.
$op=$newTransfer(placeholderComFixtureName('refuse-unsupported-placeholder.com'),1);$makeTransfer(1);$state->calls=[];$state->reads=0;$state->premium=null;$r=$engine->execute($op->id);$state->premium=false;
same(['held','registrar_read_failed'],[$r->state,$r->safe_code],'An unsupported transfer keeps its read cause');expect(!BalanceRetries::pending($r),'No retry is promised for it');
$act($r,'release');same('queued',$r->fresh()->state,'Release readmits a transfer with no automatic retry');same($r->attempt['claim_key'],$r->fresh()->attempt['claim_key'],'Release keeps the claim audit');
$makeTransfer(1);$state->premium=null;$r=$engine->execute($op->id);same(['held','registrar_read_failed'],[$r->state,$r->safe_code],'The released order really runs its checks again');$state->premium=false;
// The same transfer as an old catch-all hold: no automatic look here, so the words and Release say so.
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update(['safe_code'=>'write_acceptance_required']);$r=$op->fresh();
Illuminate\Support\Facades\Auth::setUser($staff);$notice=RequestFacts::notice($r);same(['Not sent: reason not saved.','Release to check this order again.','release',null],[$notice['title'],$notice['body'],$notice['action'],$notice['link']??null],'An old hold with no automatic look claims no cause');
$act($r,'release');$makeTransfer(1);$state->available=false;$r=$engine->execute($op->id);same(['held','name_unavailable'],[$r->state,$r->safe_code],'Release finds the real answer');$state->available=true;
echo "Transfer dispatch input, feature and pre-seal refusal proofs passed.\n"; $nativeSuccessful=true;
