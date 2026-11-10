<?php
// Enom 444f31c refunds refusal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Credit, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, ObservationCandidates, PricingRule, RefundCandidates, RefundPayment, RefundProof, Refunds, OperationTerminalProof, RegistrationRefusals};
$refunds=new Refunds($clock); $staff=commerceUser();
$role=Role::create(['name'=>'Refund evidence','permissions'=>['admin.domains.manage','admin.invoices.update']]); $staff->update(['role_id'=>$role->id]);
$op=$new(placeholderComFixtureName('refund-presence-placeholder.com')); $op->update(['state'=>'held','safe_code'=>'staff_review']);
$raw=$op->getRawOriginal(); $sraw=$op->domain->service->getRawOriginal(); $iraw=App\Models\Invoice::find($op->invoice_id)->getRawOriginal();
$itemraw=App\Models\InvoiceItem::find($op->invoice_item_id)->getRawOriginal();
$prove=static function($row) { return DB::transaction(static function()use($row){ $l=HoldingLocks::take($row->domain); RefundProof::requireUnsent($row,$l['domain']); return RefundPayment::requireCurrent($row,$l); }); };
same($op->amount,$prove($op)['amount'],'Positive exact native line before refusal matrix');
$cases=[];
foreach (['execution','provider_reference','dispatch_attempted_at','charge','order_candidates','evidence','registration','unknown_key'] as $key) {
    $cases['attempt '.$key]=static fn($row)=>$row->update(['attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent',$key=>null]]);
}
foreach (['actual','execution','refund','order_candidates'] as $key) { $cases['cost '.$key]=static fn($row)=>$row->update(['cost'=>[...($row->cost??[]),$key=>null]]); }
foreach (['claim','seal','grant','refund','refund_authorization'] as $key) { $cases['funding '.$key]=static fn($row)=>$row->update(['funding'=>[$key=>null]]); }
$cases['policy null dispatch']=static fn($row)=>$row->update(['policy'=>[...$row->policy,'dispatch_attempted_at'=>null]]);
$cases['provider order']=static fn($row)=>$row->update(['provider_order_id'=>'9003']);
$cases['lease']=static fn($row)=>$row->update(['lease_expires_at'=>now()->addMinute()]);
foreach(['pending','uncertain','succeeded','queued'] as $stateName){ $cases['state '.$stateName]=static fn($row)=>$row->update(['state'=>$stateName]); }
$cases['invoice currency']=static fn($row)=>DB::table('invoices')->where('id',$row->invoice_id)->update(['currency_code'=>'EUR']);
$cases['invoice owner']=static fn($row)=>DB::table('invoices')->where('id',$row->invoice_id)->update(['user_id'=>$staff->id]);
$cases['invoice pending']=static fn($row)=>DB::table('invoices')->where('id',$row->invoice_id)->update(['status'=>'pending']);
$cases['quantity']=static fn($row)=>DB::table('invoice_items')->where('id',$row->invoice_item_id)->update(['quantity'=>2]);
$cases['price']=static fn($row)=>DB::table('invoice_items')->where('id',$row->invoice_item_id)->update(['price'=>'99.00']);
$cases['reference']=static fn($row)=>DB::table('invoice_items')->where('id',$row->invoice_item_id)->update(['reference_id'=>999999]);
$cases['service owner']=static fn($row)=>DB::table('services')->where('id',$row->service_id)->update(['user_id'=>$staff->id]);
$cases['service currency']=static fn($row)=>DB::table('services')->where('id',$row->service_id)->update(['currency_code'=>'EUR']);
$cases['unreferenced registration']=static fn($row)=>DB::table('invoice_items')->where('id',$row->invoice_item_id)->update(['reference_type'=>null,'reference_id'=>null]);
foreach(['complimentary','unexpected'] as $basis){$cases['basis '.$basis]=static fn($row)=>DB::table('domains_operations')->where('id',$row->id)->update(['funding_basis'=>$basis]);}
$cases['nonbillable contact action']=static fn($row)=>DB::table('domains_operations')->where('id',$row->id)->update(['action'=>'contacts']);
$cases['foreign operation service']=static fn($row)=>DB::table('domains_operations')->where('id',$row->id)->update(['service_id'=>999999]);
$cases['zero amount']=static fn($row)=>DB::table('domains_operations')->where('id',$row->id)->update(['amount'=>'0.00']);
foreach($cases as $label=>$mutate){
    // Deliberate privileged fixture corruption; restore the exact issued bytes between isolated cases.
    DB::table('domains_operations')->where('id',$op->id)->update($raw); DB::table('invoices')->where('id',$op->invoice_id)->update($iraw);
    DB::table('invoice_items')->where('id',$op->invoice_item_id)->update($itemraw); DB::table('services')->where('id',$op->service_id)->update($sraw);
    $row=$op->fresh(); $mutate($row); $before=$row->fresh()->getRawOriginal(); $credit=Credit::sum('amount');
    commerceReject(fn()=>$refunds->refundHeld($staff,$row->id,'customer_cancelled'));
    same($before,$row->fresh()->getRawOriginal(),'No disposition from '.$label); same($credit,Credit::sum('amount'),'No credit from '.$label);
}
DB::table('domains_operations')->where('id',$op->id)->update($raw); DB::table('invoices')->where('id',$op->invoice_id)->update($iraw);
DB::table('invoice_items')->where('id',$op->invoice_item_id)->update($itemraw); DB::table('services')->where('id',$op->service_id)->update($sraw);
same($op->amount,$prove($op->fresh())['amount'],'Restored positive control after matrix');
$refunds->refundHeld($staff,$op->id,'customer_cancelled');
// Completed preseal local worker, normalized by the existing encoder, authorizes automatic retry credit.
$local=$new(placeholderComFixtureName('refund-local-worker-placeholder.com')); [$claimed]=$claims->claim($local->id); $claims->finish($claimed);
OperationTerminalProof::encode($claims,$local->id); expect($refunds->creditRejected($local->id)!==null,'Positive terminal-unsent automatic refund');
// A completed preseal worker held for lost seal authority can be disposed by fresh staff directly.
$invalid=$new(placeholderComFixtureName('refund-seal-authority-placeholder.com')); [$claim]=$claims->claim($invalid->id); $claims->finish($claim);
$invalid->refresh()->update(['safe_code'=>'seal_authority_invalid']);
expect($refunds->refundHeld($staff,$invalid->id,'authority_changed')!==null,'Held seal-authority-invalid positive credit');
// All sent/unknown/fallback evidence remains disqualifying regardless of held/candidate labels.
foreach(['accepted','null','absent'] as $mode){
    [$sent]=$prepare(placeholderComFixtureName('refund-sent-'.$mode.'-placeholder.com'),$mode); $sent->update(['state'=>'held','safe_code'=>'staff_review',
        'policy'=>[...$sent->policy,'renewal_execution'=>['refundable_later'=>true]]]);
    commerceReject(fn()=>$refunds->refundHeld($staff,$sent->id,'customer_cancelled'));
}
// Complimentary refusals without retained proof come first by ID, yet a later paid return still gets its turn.
Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
// The complimentary closure cursor arrives in its own migration (older code keeps it in the cache).
if(is_file(dirname(__DIR__).'/Domains/Services/ClosureCursorSchema.php')){Paymenter\Extensions\Servers\Domains\Services\ClosureCursorSchema::install();}
foreach(Paymenter\Extensions\Servers\Domains\Models\Account::orderBy('server_id')->get() as $a){DB::table('domains_observation_queues')->insertOrIgnore(['server_id'=>$a->server_id,'account_fingerprint'=>$a->account_fingerprint]);}
$unproven=[];
for($i=0;$i<25;$i++){
    $row=$new(placeholderComFixtureName('refund-unproven-'.$i.'-placeholder.com'));
    DB::table('domains_operations')->where('id',$row->id)->update(['state'=>'rejected','funding_basis'=>'complimentary','safe_code'=>RegistrationRefusals::CODE]);
    $unproven[]=$row->id;
}
$eligible=$new(placeholderComFixtureName('refund-after-unproven-placeholder.com')); [$claim]=$claims->claim($eligible->id); $claims->finish($claim); OperationTerminalProof::encode($claims,$eligible->id);
$enom=$eligible->server_id; $other=(int)DB::table('domains_observation_queues')->where('server_id','!=',$enom)->value('server_id');
$paid=RefundCandidates::query(Operation::where('server_id',$enom))->where('funding_basis','paid_invoice')->orderBy('id')->pluck('id')->all();
expect(count($paid)<25&&in_array($eligible->id,$paid,true),'Paid returns fit one page once complimentary refusals are paged apart');
$held=static fn()=>[Operation::whereIn('id',$unproven)->orderBy('id')->get()->map->getRawOriginal()->all(),Credit::sum('amount')];$retained=$held();
$page=ObservationCandidates::next('refund')[$enom]??[];
expect(array_diff($paid,$page)===[],'Twenty-five earlier unproven complimentary refusals leave the paid return on the first page');
expect(array_search($eligible->id,$page,true)<array_search($unproven[0],$page,true),'Paid returns run before complimentary closures');
$credit=Credit::sum('amount');
foreach($page as $id){$row=Operation::find($id);if($row&&RefundCandidates::automatic($row)){try{$refunds->creditRejected((int)$id);}catch(DomainException){}}}
expect(isset($eligible->fresh()->funding['refund'])&&PricingRule::minor((string)Credit::sum('amount'))===PricingRule::minor((string)$credit)+PricingRule::minor($eligible->amount),'The paid return is credited in the same turn');
same($retained[0],$held()[0],'Unproven complimentary refusals stay unchanged');
foreach($unproven as $id){$row=Operation::findOrFail($id);expect(!RefundCandidates::automatic($row),'Unproven complimentary refusal stays out of native refund locks');commerceReject(fn()=>$refunds->creditRejected($id));}
same($retained[0],$held()[0],'Direct credit still refuses unproven complimentary refusals');
// A smaller page still rechecks every complimentary refusal within a bounded number of turns, after its cursor wraps.
// The cursor lives on the queue row, so losing the cache before every turn never restarts the page.
$seen=[];
for($turn=0;$turn<6;$turn++){
    Illuminate\Support\Facades\Cache::flush();
    $page=ObservationCandidates::next('refund',8,10)[$enom]??[];
    expect(array_diff($paid,$page)===[],'Every turn keeps the paid page: '.$turn);
    expect(count(array_intersect($page,$unproven))<=10,'Complimentary review stays within its page: '.$turn);
    $seen=[...$seen,...array_intersect($page,$unproven)];
}
same($unproven,array_values(array_unique(array_intersect($unproven,$seen))),'Each unproven complimentary refusal is rechecked within one cursor pass, through repeated cache loss');
// A cache outage keeps the paid page and the complimentary page's place.
$comp=RefundCandidates::complimentary(Operation::where('server_id',$enom))->orderBy('id')->pluck('id')->all();
$cursor=static fn():int=>Illuminate\Support\Facades\Schema::hasColumn('domains_observation_queues','complimentary_cursor')?(int)DB::table('domains_observation_queues')->where('server_id',$enom)->value('complimentary_cursor'):0;
for($i=0;$i<2&&$cursor()===0;$i++){ObservationCandidates::next('refund',8,10);}
$at=$cursor();expect($at>0,'The complimentary page stopped partway, with its place on the queue row');
$cacheManager=Illuminate\Support\Facades\Cache::getFacadeRoot();
Illuminate\Support\Facades\Cache::swap(new class { public function __call($method,$arguments){throw new RuntimeException('Synthetic cache outage');} });
try{$page=ObservationCandidates::next('refund',8,10)[$enom]??[];}finally{Illuminate\Support\Facades\Cache::swap($cacheManager);}
expect(array_diff($paid,$page)===[],'A cache outage keeps the paid page');
same(array_slice(array_values(array_filter($comp,static fn(int $id)=>$id>$at)),0,10),array_values(array_diff($page,$paid)),'A cache outage continues the complimentary page from its place');
same([$enom],array_keys(ObservationCandidates::next('refund',1)),'One account turn');
same([$other],array_keys(ObservationCandidates::next('refund',1)),'The other account still gets the next turn');
echo "Independent negative proof and null/presence/route refusal matrix passed\n"; $nativeSuccessful=true;
