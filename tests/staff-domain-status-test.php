<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Staff domain list: the customer's status words, and one next step under them.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Invoice;
use Illuminate\Support\Facades\{Auth, DB, Schema};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, OperationResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, DomainQueries, Labels, RequestFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, RequestStatus};
use Paymenter\Extensions\Servers\Domains\Services\{ManagementCorrections, ObligationAlerts};
staffPanel(); [, $seed]=staffData();
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);

Schema::disableForeignKeyConstraints();
Account::where('server_id',$seed->server_id)->update(['processing_enabled'=>true]);
$n=0;
$row=static function(string $status, ?string $action, ?string $state, ?string $code=null, int $days=120, array $alerts=[], array $funding=[]) use ($seed, &$n): Domain {
    $n++; $label='w2-case-'.$n;
    $d=$seed->replicate()->fill(['domain'=>$label.'.example.com','sld'=>$label,'service_id'=>2000+$n,'status'=>$status,
        'provider_expires_at'=>today()->addDays($days)->format('Y-m-d')]);
    $d->save();
    DB::table('services')->insert(['id'=>$d->service_id,'user_id'=>$d->user_id,'order_id'=>1,'product_id'=>1,'plan_id'=>1,'status'=>'pending','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    if ($action) {
        $seed->operations()->sole()->replicate()->fill(['domain_id'=>$d->id,'service_id'=>$d->service_id,'authority_generation'=>$d->authority_generation,
            'operation_key'=>$label,'action'=>$action,'state'=>$state,'safe_code'=>$code,'attempt'=>[],'policy'=>[],'payload'=>[],'cost'=>[],'funding'=>$funding])->save();
    }
    foreach ($alerts as $alert) { ObligationAlerts::record($d,$alert,time()); }
    return $d->fresh();
};
$req=static fn(Domain $d)=>'Check request #'.$d->latestAcquisition->id.'.';
$cases=[];
// The five rows Ilya saw on the demo walk (W2).
$d=$row('active','register','succeeded',null,730,['operation_review','renewal_unpaid']);
$cases['walk: active with a saved correction review']=[$d,'active','Active','Registrar request needs a check. Check the request.'];
foreach (['walk: paid registration held for staff (1)','walk: paid registration held for staff (2)'] as $name) {
    $d=$row('registration_pending','register','held','staff_review_required',120,['operation_review']);
    $cases[$name]=[$d,'on_hold','On hold','Put on hold by staff. '.$req($d)];
}
$d=$row('registration_pending','register','cancelled');
$cases['walk: old cancelled request on a pending domain']=[$d,'pending_registration','Pending registration','Request cancelled, order still open. Cancel the order to release the name. '.$req($d)];
$d=$row('registration_pending','register','held','contact_details_required',120,['operation_review']);
$cases['walk: contact details hold']=[$d,'action_required','Action required','Finish setup. Contact details needed. '.$req($d)];
// Every other case the list can show.
$cases['unpaid invoice']=[$row('registration_pending','register','awaiting_payment'),'awaiting_payment','Awaiting payment','Invoice not paid. Customer has not paid the invoice. Not sent yet.'];
$cases['auth code']=[$row('transfer_pending','transfer','awaiting_code'),'action_required','Action required','Auth code needed. Customer has not added the auth code. Not sent yet.'];
$d=$row('registration_pending','register','held','nameservers_required');
$cases['nameservers hold']=[$d,'action_required','Action required','Finish setup. Not sent: nameservers missing. '.$req($d)];
$d=$row('transfer_pending','transfer','held','transfer_needs_action');
$cases['held transfer']=[$d,'under_review','Under review','Old registrar needs approval. '.$req($d)];
$d=$row('registration_pending','register','uncertain');
$cases['unconfirmed registration']=[$d,'pending_registration','Pending registration','Registrar has not confirmed the result. '.$req($d)];
$cases['queued registration']=[$row('registration_pending','register','queued'),'pending_registration','Pending registration','Registration in progress. Nothing to do yet.'];
$cases['running transfer']=[$row('transfer_pending','transfer','running'),'pending_transfer','Pending transfer','Transfer in progress. Nothing to do yet.'];
$cases['queued with an alert']=[$row('registration_pending','register','queued',null,120,['unknown_cost']),'pending_registration','Pending registration',"Cost missing. Check the registrar account's price."];
$cases['stored review']=[$row('needs_review','transfer','succeeded'),'under_review','Under review','Check the domain and its requests.'];
$cases['no expiry']=[$row('active','transfer','succeeded',null,120),'under_review','Under review','Expiry missing. Check the domain.'];
DB::table('domains_domains')->where('id',$cases['no expiry'][0]->id)->update(['provider_expires_at'=>null]);
$cases['active']=[$row('active','transfer','succeeded'),'active','Active',null];
$cases['past expiry']=[$row('active','transfer','succeeded',null,-1),'expired','Expired','Check renewal options and status at the registrar.'];
$cases['grace']=[$row('grace','transfer','succeeded',null,-5),'expired','Expired','Check renewal options and status at the registrar.'];
$cases['redemption']=[$row('redemption','transfer','succeeded',null,-40),'redemption','Redemption','Check renewal options and status at the registrar.'];
$cases['rejected']=[$row('registration_rejected','register','rejected','preflight_failed'),'failed','Failed','Registration did not finish.'];
$cases['credited order']=[$row('registration_rejected','register','rejected','refund_approved',funding:['refund'=>['to'=>'account_credit']]),'cancelled','Cancelled','Order cancelled.'];
$cases['cancelled order']=[$row('cancelled','register','disposed','acquisition_cancelled_unpaid'),'cancelled','Cancelled',null];
$waiting=$row('something_new','nameservers','queued');$cases['unknown status with a request waiting']=[$waiting,'under_review','Under review','Nameservers in progress. Nothing to do yet.'];
$stuck=$row('something_new','nameservers','held','management_unconfirmed');$cases['unknown status keeps the customer word, then the request']=[$stuck,'under_review','Under review','Registrar has not confirmed the result. Check request #'.$stuck->operations()->sole()->id.'.'];
$unknown=$row('something_new','transfer','succeeded');$cases['unknown status']=[$unknown,'under_review','Under review','Status missing. Check request #'.$unknown->latestAcquisition->id.'.'];
// An order reads as it does for the customer once its invoice is known. Paid orders keep only what the customer still owes.
$invoiced=static function(Domain $d, string $status, ?int $owner=null): Domain {
    $invoice=Invoice::withoutEvents(fn()=>Invoice::create(['user_id'=>$owner??$d->user_id,'currency_code'=>'USD','status'=>$status,'due_at'=>now()]));
    $d->latestAcquisition->forceFill(['invoice_id'=>$invoice->id])->saveQuietly(); return $d->fresh();
};
$unpaid='Invoice not paid. Customer has not paid the invoice. Not sent yet.';
$cases['order: unpaid invoice']=[$invoiced($row('registration_pending','register','awaiting_payment'),'pending'),'awaiting_payment','Awaiting payment',$unpaid];
$cases['order: paid invoice of another customer']=[$invoiced($row('registration_pending','register','awaiting_payment'),'paid',999),'awaiting_payment','Awaiting payment',$unpaid];
$cases['order: paid, nothing left for the customer']=[$invoiced($row('registration_pending','register','awaiting_payment'),'paid'),'pending_registration','Pending registration','Registration in progress. Nothing to do yet.'];
$cases['order: paid, auth code missing']=[$invoiced($row('transfer_pending','transfer','awaiting_payment'),'paid'),'action_required','Action required','Auth code needed. Customer has not added the auth code. Not sent yet.'];
// Saving the code completes the customer's part before the worker changes awaiting_code.
$codeSaved=$invoiced($row('transfer_pending','transfer','awaiting_code'),'paid');
$codeSaved->latestAcquisition->forceFill(['payload'=>['epp'=>'synthetic-transfer-code']])->saveQuietly();
$cases['order: paid, code saved before pickup']=[$codeSaved->fresh(),'pending_transfer','Pending transfer','Transfer in progress. Nothing to do yet.'];
$d=$invoiced($row('registration_pending','register','awaiting_payment'),'paid');
DB::table('domains_domains')->where('id',$d->id)->update(['registrar_key'=>'namesilo']); DB::table('domains_operations')->where('domain_id',$d->id)->update(['registrar_key'=>'namesilo']);
// No NameSilo account here, so the list note leads with that, as the domain's head does.
$cases['order: paid, setup not finished']=[$d->fresh(),'action_required','Action required','Finish setup. Customer has not finished setup. Not sent yet.',DomainFacts::NO_ACCOUNT];
$d=$invoiced($row('registration_pending','register','awaiting_payment'),'pending'); DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']);
$cases['order: closed with an open invoice']=[$d,'under_review','Under review','Order cancelled. Check the domain and its requests.'];
// The live demo rows from the round 2 click test. An alert left by an old request must not outrank the open one.
$request=static function(Domain $d, string $action, string $state, array $extra=[]) use ($seed, &$n): Operation {
    $n++;
    $op=$seed->operations()->sole()->replicate()->fill(['domain_id'=>$d->id,'service_id'=>$d->service_id,'authority_generation'=>$d->authority_generation,
        'operation_key'=>'w2-request-'.$n,'action'=>$action,'state'=>$state,'safe_code'=>null,'attempt'=>[],'policy'=>[],'payload'=>[],'cost'=>[],'funding'=>[],...$extra]);
    $op->save(); return $op;
};
$sent=static fn(Domain $d)=>['base_expires_at'=>$d->provider_expires_at->format('Y-m-d')];
// Domain 1: the registration flagged a nameserver fix. Request #14 made it, and renewal #18 is unpaid.
$confirmed=static fn(Operation $op)=>['completed_at'=>now(),'attempt'=>['seal'=>'sealed',
    'management_reads'=>[['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,'action'=>'nameservers','confirmed'=>true]]]];
$renewing=static function(?string $fix) use ($row, $request, $sent, $confirmed): array {
    $d=$row('active',null,null,null,730);
    $original=$request($d,'register','succeeded',['safe_code'=>'registration_correction_required','completed_at'=>now(),
        'attempt'=>['seal'=>'sealed','corrections'=>['nameservers'=>['state'=>'authorization_required','readback_required'=>true]]],
        'policy'=>['reconciliation'=>['staff_alert'=>true,'reason'=>'correction_required','observed_corrections'=>[]]]]);
    $correction=$fix===null?null:$request($d,'nameservers',$fix,['operation_key'=>$original->operation_key.'-fix','provider_order_id'=>null,'confirmed_expires_at'=>null,
        'attempt'=>$fix==='rejected'?['seal'=>'absent','send_state'=>'definitely_unsent']:[],
        'policy'=>['management'=>['correction'=>$original->operation_key,'correction_link'=>['operation_key'=>$original->operation_key,'section'=>'nameservers','generation'=>$d->authority_generation]]]]);
    if ($fix==='succeeded') { $correction->fill($confirmed($correction))->saveQuietly(); }
    $renewal=$request($d,'renew','awaiting_payment',$sent($d));
    ObligationAlerts::record($d,'operation_review',time()-60,(int)$original->id);
    return [$d->fresh(),$original,$renewal,$correction];
};
[$one,,$oneRenewal]=$renewing('succeeded');
$cases['live: domain 1, fixed registration and an unpaid renewal']=[$one,'active','Active','Customer has not paid the invoice. Not sent yet.'];
[$unfixed,$unfixedOriginal]=$renewing(null);
$cases['registration fix still to do, unpaid renewal']=[$unfixed,'active','Active','Registrar request needs a check. Check request #'.$unfixedOriginal->id.'.'];
// A request that only looks close to done proves nothing: the alert stays live and names the request.
foreach (['queued'=>'correction queued, not sent','rejected'=>'correction retired, never sent'] as $state=>$name) {
    [$d,$original,,$correction]=$renewing($state);
    expect(ManagementCorrections::resolvedFor($correction,$original,$d),'Fixture: the renewal barrier accepts this request');
    $cases[$name]=[$d,'active','Active','Registrar request needs a check. Check request #'.$original->id.'.'];
}
// Domain 2: the hold was released and the request is queued, but the alert from the hold is still open.
$released=static fn(?int $at=null)=>['policy'=>['staff_actions'=>[['action'=>'hold','phase'=>'completed','at'=>date(DATE_ATOM,time()-120)],
    ['action'=>'release','phase'=>'completed','at'=>date(DATE_ATOM,$at??time())]]]];
$two=$row('registration_pending',null,null); $twoRequest=$request($two,'register','queued',$released());
ObligationAlerts::record($two,'operation_review',time()-60,(int)$twoRequest->id);
$cases['live: domain 2, hold released']=[$two->fresh(),'pending_registration','Pending registration','Registration in progress. Nothing to do yet.'];
// Raised again after the release, so dispatch still refuses it. Same when no one ever released it.
$d=$row('registration_pending',null,null); $op=$request($d,'register','queued',$released(time()-60));
ObligationAlerts::record($d,'operation_review',time(),(int)$op->id);
$cases['alert raised again after the release']=[$d->fresh(),'pending_registration','Pending registration','Registrar request needs a check. Check request #'.$op->id.'.'];
$d=$row('registration_pending','register','queued');
ObligationAlerts::record($d,'operation_review',time(),(int)$d->latestAcquisition->id);
$cases['registration dispatch refused']=[$d,'pending_registration','Pending registration','Registrar request needs a check. '.$req($d)];
// RenewalReminders raises this on an unpaid renewal when processing or renewal authority fails. The request itself does not change.
$d=$row('active',null,null,null,730); $blocked=$request($d,'renew','awaiting_payment',$sent($d));
ObligationAlerts::record($d,'held_obligation',time(),(int)$blocked->id);
$cases['unpaid renewal blocked for staff']=[$d->fresh(),'active','Active','Renewal on hold. Check the request before expiry. Check request #'.$blocked->id.'.'];
$d=$row('active',null,null,null,730); $request($d,'renew','queued',$sent($d));
$cases['renewal on its way']=[$d->fresh(),'active','Active','Renewal in progress. Nothing to do yet.'];
$d=$row('active',null,null,null,730); $held=$request($d,'renew','held',$sent($d));
$cases['renewal on hold']=[$d->fresh(),'active','Active','Not sent. Check request #'.$held->id.'.'];
// Request #42: held, released, finished while sync was paused. The hold's alert is over, whatever the Sync says.
$d=$row('active','register','succeeded',null,730);
ObligationAlerts::record($d,'operation_review',time()-60,(int)$d->latestAcquisition->id);
$cases['finished request with an alert from its hold']=[$d,'active','Active',null];

$page=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('w2-case-')->set('tableRecordsPerPage',100)->assertSuccessful();
$columns=$page->instance()->getTable()->getColumns(); $records=$page->instance()->getTableRecords()->keyBy('id');
$ids=static fn(string $key)=>DomainQueries::status(Domain::query()->where('domain','like','w2-case-%'),[$key])->orderBy('id')->pluck('id')->all();
$members=[]; $wrong=[];
foreach ($cases as $name=>$case) {
    [$d,$key,$label,$step]=$case; $note=isset($case[4])?$case[4].' '.$step:$step;
    $cell=$columns['status']->record($records[$d->id]); $status=DomainFacts::status($d->fresh());
    $list=[$cell->getState(),$columns['note']->record($records[$d->id])->getState()];
    if ($list!==[$label,$note]) { $wrong[]=$name.': list shows "'.implode(' / ',array_filter($list)).'", want "'.implode(' / ',array_filter([$label,$note])).'"'; }
    $line=DomainFacts::statusLine($status);
    if ([$status['label'],$line]!==[$label,$step]) { $wrong[]=$name.': domain page shows "'.implode(' / ',array_filter([$status['label'],$line])).'", want the same as the list'; }
    $members[$key][]=$d->id;
}
// One head word: for every status, staff read the customer's word and add their detail in the step.
$heads=[]; foreach ($cases as $name=>[$d]) { $s=DomainFacts::status($d->fresh()); $customer=$s['key']==='on_hold'?'On hold':DomainStatus::SET[$s['key']][0]; if ($s['label']!==$customer) { $heads[]=$name.': staff "'.$s['label'].'", customer "'.$customer.'"'; } }
same([],$heads,'Customers and staff read one head word for every status');
same('warning',DomainFacts::status($cases['held transfer'][0]->fresh())['tone'],'A held transfer is a staff job, so staff read it in amber');
// The amber note is for staff jobs only.
same([true,false],array_map(fn(string $name)=>DomainFacts::status($cases[$name][0]->fresh())['act'],['walk: paid registration held for staff (1)','unpaid invoice']),'A staff job gets the mark, waiting on the customer does not');
same(false,DomainFacts::status($codeSaved->fresh())['act'],'A paid transfer with its code saved needs no staff action');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$codeSaved->id])->assertSee('Transfer in progress. Nothing to do yet.')->assertDontSee('Request status unclear.');
Account::where('server_id',$seed->server_id)->update(['processing_enabled'=>false]);
same([true,'Not sent. Orders are off for this account. Turn them on in Registrars.'],[DomainFacts::status($codeSaved->fresh())['act'],DomainFacts::status($codeSaved->fresh())['step']],'A code saved before pickup still respects Orders off');
Account::where('server_id',$seed->server_id)->update(['processing_enabled'=>true]);
same(false,isset(DomainFacts::for($cases['past expiry'][0]->fresh())['Next invoice']),'An expired domain has no next invoice to chase');
// A paid renewal stays unsent while Orders are off, so staff must read that, not Nothing to do yet.
$d=$row('active',null,null,null,730); $paid=Invoice::withoutEvents(fn()=>Invoice::create(['user_id'=>$d->user_id,'currency_code'=>'USD','status'=>'paid','due_at'=>now()]));
$stopped=$request($d,'renew','queued',[...$sent($d),'invoice_id'=>$paid->id]); $members['active'][]=$d->id;
Account::where('server_id',$stopped->server_id)->update(['processing_enabled'=>false]);
same('Not sent. Orders are off for this account. Turn them on in Registrars.',DomainFacts::status($d->fresh())['step'],'Queued renewal says Orders are off on the domain: '.DomainFacts::status($d->fresh())['step']);
same('Not sent. Orders are off for this account.',RequestFacts::reason($stopped->fresh()),'Queued renewal says Orders are off on the request');
Account::where('server_id',$stopped->server_id)->update(['processing_enabled'=>true]);
// An inspection closes only what is proven cleared, and the alert never flips while a fix moves along.
$apart=static fn(Domain $d)=>DB::table('domains_domains')->where('id',$d->id)->update(['domain'=>'w2-inspect-'.$d->id.'.example.com']);
$inspect=static function(Domain $d,array $raised=[]): void {
    $d=$d->fresh(); $now=time();
    ObligationAlerts::finish($d,[...ObligationAlerts::retained(['domain'=>$d,'operations'=>$d->operations()->get()],$now),...$raised],$now);
};
$open=static fn(Domain $d,string $code,?int $id)=>DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code',$code)
    ->where('operation_id',$id)->whereNull('resolved_at')->exists();
$check=static function(string $name,mixed $want,mixed $got) use (&$wrong): void { if ($want!==$got) { $wrong[]=$name.': got '.json_encode($got).', want '.json_encode($want); } };
[$moving,$movingOriginal,,$fix]=$renewing('queued'); $apart($moving); $seen=[];
foreach ([['queued',[]],['running',[]],['succeeded',$confirmed($fix)],['succeeded',[]]] as [$state,$extra]) {
    $fix->fill(['state'=>$state,...$extra])->saveQuietly(); $inspect($moving);
    $seen[]=[$state,$open($moving,'operation_review',$movingOriginal->id),DomainFacts::status($moving->fresh())['step']];
}
$review='Registrar request needs a check. Check request #'.$movingOriginal->id.'.'; $paying='Customer has not paid the invoice. Not sent yet.';
$check('Alert while a fix moves along',[['queued',true,$review],['running',true,$review],['succeeded',false,$paying],['succeeded',false,$paying]],$seen);
[$closed,$closedOriginal]=$renewing('succeeded'); $apart($closed); ObligationAlerts::record($closed,'template_failed',time()); $inspect($closed);
$check('Finished fix closes',false,$open($closed,'operation_review',$closedOriginal->id));
$check('Inspection leaves other work open',true,$open($closed,'template_failed',null));
foreach ([null,'rejected'] as $state) {
    [$kept,$keptOriginal]=$renewing($state); $apart($kept); $inspect($kept); $inspect($kept);
    $check('Unfinished fix stays open: '.($state??'none'),true,$open($kept,'operation_review',$keptOriginal->id));
}
$gone=$row('registration_pending',null,null); $apart($gone); $goneRequest=$request($gone,'register','queued',$released());
ObligationAlerts::record($gone,'operation_review',time()-60,(int)$goneRequest->id); $inspect($gone);
$check('Released hold closes',false,$open($gone,'operation_review',$goneRequest->id));
$stuck=$row('registration_pending',null,null,null,120); $apart($stuck); $stuckRequest=$request($stuck,'register','held');
ObligationAlerts::record($stuck,'operation_review',time()-60,(int)$stuckRequest->id); $inspect($stuck);
$check('Request still on hold stays open',true,$open($stuck,'operation_review',$stuckRequest->id));
// Success closes the alert at once, so the Open alerts filter agrees, and a saved money difference keeps it.
$settle=static function(array $policy) use ($row, $apart, $request, $open): bool {
    $d=$row('registration_pending',null,null); $apart($d); $op=$request($d,'register','running',['policy'=>$policy]);
    ObligationAlerts::record($d,'operation_review',time()-60,(int)$op->id); $op->update(['state'=>'succeeded']);
    return $open($d,'operation_review',$op->id);
};
$check('Success closes the alert with no inspection',false,$settle([]));
$check('Success with a difference keeps the alert',true,$settle(['reconciliation'=>['staff_alert'=>true,'reason'=>'charge_changed']]));
$named=$row('registration_pending','register','held','staff_review_required'); ObligationAlerts::record($named,'operation_review',time(),(int)$named->latestAcquisition->id);
$check('Held request is named once, not again on the Registrar row',false,str_contains((string)DomainFacts::for($named)['Registrar']['sub'],'needs a check'));
$apart($named);
$planned=$row('active',null,null,null,730); $apart($planned); $plannedRequest=$request($planned,'renew','awaiting_payment',$sent($planned));
$plannedKey=ObligationAlerts::record($planned,'held_obligation',time(),(int)$plannedRequest->id); $inspect($planned,[$plannedKey]);
$check('Alert raised by this inspection stays open',true,$open($planned,'held_obligation',$plannedRequest->id));
// Waits the system retries on its own raise nothing. Anything abnormal alerts at once.
$read=static fn(?string $status,string $reason='original_attempt_unresolved')=>['reconciliation'=>['staff_alert'=>true,'reason'=>$reason,...($status?['transfer_status'=>$status,'read_at'=>time()]:[])]];
$sentOk=['seal'=>'sealed','execution'=>['disposition'=>'pending']]; $retry=['renewal_execution'=>['staff_alert'=>true,'reason'=>'retry','refundable_later'=>false]];
foreach ([
    'pending transfer'=>[false,'transfer','uncertain','reconciliation_required',$read('pending'),120,1,$sentOk],
    'pending transfer keeps polling past the old limit'=>[false,'transfer','uncertain','reconciliation_required',$read('pending','poll_limit_reached'),120,1,$sentOk],
    'accepted transfer not read yet'=>[false,'transfer','uncertain','reconciliation_required',$read(null),120,0,$sentOk],
    'transfer past its seven days'=>[true,'transfer','uncertain','reconciliation_required',$read('pending'),120,8,$sentOk],
    'transfer send not confirmed'=>[true,'transfer','uncertain','reconciliation_required',$read(null),120,0,[]],
    'transfer read conflicts'=>[true,'transfer','uncertain','reconciliation_required',$read('rejected'),120,1,$sentOk],
    'transfer lost its worker'=>[true,'transfer','uncertain','stale_claim',$read('pending'),120,1,$sentOk],
    'transfer needs old registrar'=>[true,'transfer','held','transfer_needs_action',$read('needs_action'),120,1,$sentOk],
    'renewal rate limit retry'=>[false,'renew','queued','renewal_rate_limited',$retry,120,0,[]],
    'renewal read retry'=>[false,'renew','queued','renewal_reads_unavailable',$retry,120,0,[]],
    'urgent paid renewal retry'=>[true,'renew','queued','renewal_reads_unavailable',$retry,5,0,[]],
    'renewal needs funds'=>[true,'renew','queued','registrar_balance_insufficient_or_unknown',$retry,120,0,[]],
    'renewal needs setup'=>[true,'renew','queued','renewal_acceptance_required',$retry,120,0,[]],
    'renewal fraud hold'=>[true,'renew','held','staff_review_required',$retry,120,0,[]],
    'renewal charge overrun'=>[true,'renew','succeeded','actual_charge_changed',$retry,120,0,[]],
] as $name=>[$want,$action,$state,$code,$policy,$days,$age,$attempt]) {
    $d=$row('active',null,null,null,$days); $apart($d);
    $op=$request($d,$action,$state,['safe_code'=>$code,'policy'=>$policy,'attempt'=>$attempt,'started_at'=>now()->subDays($age)]);
    $inspect($d);
    $check('Alert: '.$name,$want,$open($d,$action==='renew'&&$state==='held'?'held_obligation':'operation_review',$op->id));
}
// A dropped alert keeps its row, and the same alert comes back when the wait runs out.
$quiet=$row('transfer_pending',null,null); $apart($quiet);
$quietRequest=$request($quiet,'transfer','uncertain',['safe_code'=>'reconciliation_required','policy'=>$read('pending'),'attempt'=>$sentOk,'started_at'=>now()->subDay()]);
$quietKey=ObligationAlerts::record($quiet,'operation_review',time()-60,(int)$quietRequest->id); $inspect($quiet);
$check('Wait closes an older alert',false,$open($quiet,'operation_review',$quietRequest->id));
$check('Closed alert keeps its record',1,DB::table('domains_obligation_alerts')->where('alert_key',$quietKey)->count());
$quietRequest->forceFill(['started_at'=>now()->subDays(8)])->saveQuietly(); $inspect($quiet);
$check('Same alert returns when the wait runs out',[true,1],[$open($quiet,'operation_review',$quietRequest->id),DB::table('domains_obligation_alerts')->where('alert_key',$quietKey)->count()]);
if ($wrong) { fwrite(STDERR,implode("\n",$wrong)."\n"); }
same([],$wrong,'Every row has one status and one next step');
expect(!isset($columns['alert']),'No separate alert column: the next step is under the status');
// The filter offers the customer's words.
same(['active'=>'Active','pending_registration'=>'Pending registration','pending_transfer'=>'Pending transfer','awaiting_payment'=>'Awaiting payment','action_required'=>'Action required','under_review'=>'Under review',
    'expired'=>'Expired','redemption'=>'Redemption','failed'=>'Failed','cancelled'=>'Cancelled','on_hold'=>'On hold'],DomainResource::statusOptions(),'Status filter set');
foreach (array_keys(DomainResource::statusOptions()) as $key) {
    $expected=$members[$key]??[]; sort($expected);
    same($expected,$ids($key),'Status filter matches the rows: '.$key);
}
// The filter on the real list page, with its search and page size, picks the same rows.
$filtered=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('w2-case-')->set('tableRecordsPerPage',100)->filterTable('status',['awaiting_payment']);
expect($filtered->instance()->getTableRecords()->contains('id',$cases['order: unpaid invoice'][0]->id)&&!$filtered->instance()->getTableRecords()->contains('id',$one->id),'Awaiting payment shows the unpaid order, not an Active domain');
// One answer on every surface: the list, the heading, the Registration card and the request page.
$oneView=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$one->id])->assertSee('Active')->assertDontSee('Registrar request needs a check');
expect(str_contains(strip_tags((string)$oneView->instance()->getSubheading()),'Customer has not paid the invoice. Not sent yet.'),'Domain 1 heading names the open request');
same(['Awaiting payment','Customer has not paid the invoice. Not sent yet.'],array_values(array_intersect_key(RequestStatus::for($oneRenewal->state),['label'=>1,'reason'=>1])),'Request page says the same');
// An early renewal says so: the same words on the heading and the request page, and only until the billing window opens.
$calendar=static function(int $issue,int $due)use($oneRenewal):void {DB::table('domains_operations')->where('id',$oneRenewal->id)->update(['policy'=>encrypt(json_encode([...$oneRenewal->policy,'calendar'=>['issue'=>today()->addDays($issue)->format('Y-m-d'),'due'=>today()->addDays($due)->format('Y-m-d')]]),false)]);};
$early='Invoice ready. Due '.today()->addDays(113)->format('M j, Y').'.';$calendar(99,113);
expect(str_contains(strip_tags((string)Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$one->id])->instance()->getSubheading()),$early),'Early renewal, staff heading');same($early,Labels::requestStatus($oneRenewal->fresh())['reason'],'Early renewal, request page');
$calendar(0,14);same('Customer has not paid the invoice. Not sent yet.',Labels::requestStatus($oneRenewal->fresh())['reason'],'Inside the billing window it is a normal unpaid invoice');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$two->id])->assertSee('Pending registration')->assertDontSee('Registrar request needs a check');
// Real staff work names the request and links to it, in the list and in the heading.
$link=OperationResource::getUrl('view',['record'=>$unfixedOriginal->id]);
same($link,$columns['note']->record($records[$unfixed->id])->getUrl(),'The note opens the request that needs the work');
expect(str_contains((string)Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$unfixed->id])->instance()->getSubheading()?->toHtml(),'href="'.e($link).'"'),'The heading links to the request that needs the work');
same(null,$columns['note']->record($records[$one->id])->getUrl(),'No request link when there is nothing to open');
// Auto-renew off near expiry reads as the customer sees it, and the filter finds it.
$soon=$row('active','transfer','succeeded',null,10); $soon->forceFill(['renewal_policy'=>['owner_auto_renew'=>false]])->saveQuietly();
same(['Action required','Auto-renew off'],array_values(array_intersect_key(DomainFacts::status($soon->fresh()),['label'=>1,'reason'=>1])),'Auto-renew off near expiry');
expect(in_array($soon->id,$ids('action_required'),true),'The status filter reads auto-renew off');
// A list row is amber when its sync stopped, so its note says so too. The same request on a healthy domain stays as it is.
$job=['reason'=>null,'step'=>'Registrar request needs a check. Check request #9.','act'=>true,'request'=>9];
same(['text'=>'Sync stopped. Registrar request needs a check. Check request #9.','act'=>true],DomainFacts::note($job,'failed'),'A stopped sync is in the note');
same(['text'=>'Registrar request needs a check. Check request #9.','act'=>true],DomainFacts::note($job,null),'No stopped sync, no mention');
same('Sync stopped after a failed check. Use Resume sync below.',DomainFacts::note(['reason'=>null,'step'=>'Sync stopped after a failed check. Use Resume sync below.','act'=>true,'request'=>null],'failed')['text'],'A step about the sync does not say it twice');
// The Registrar column is hidden by default, so a missing registrar account shows in the Note, amber like the domain's head.
$orphan=$row('active','transfer','succeeded'); DB::table('domains_domains')->where('id',$orphan->id)->update(['account_fingerprint'=>str_repeat('0',64)]);
$page=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('w2-case-')->set('tableRecordsPerPage',100);
$columns=$page->instance()->getTable()->getColumns(); $record=$page->instance()->getTableRecords()->keyBy('id')[$orphan->id];
same(['Registrar account not found. Check Registrars.','warning'],[$columns['note']->record($record)->getState(),$columns['status']->record($record)->getColor($columns['status']->record($record)->getState())],'The list says the registrar account is missing');
// An ended domain places no orders, so a saved pause on it says nothing. A live one still says it.
$pausedPolicy=['automatic_spend_paused'=>true];
$ended=$row('cancelled','register','disposed'); $ended->forceFill(['renewal_policy'=>$pausedPolicy])->saveQuietly();
$live=$row('active','transfer','succeeded'); $live->forceFill(['renewal_policy'=>$pausedPolicy])->saveQuietly();
expect(!str_contains((string)DomainFacts::for($ended->fresh())['Registrar']['sub'],'orders paused'),'Cancelled domain has no paused orders');
expect(str_contains((string)DomainFacts::for($live->fresh())['Registrar']['sub'],'orders paused'),'Live domain keeps the pause');
// Orders off: the list and the heading read the customer's word, then the request's reason. The request reads the same word.
$offTransfer=$row('transfer_pending','transfer','held','processing'); $offChange=$row('something_new','nameservers','queued');
Account::where('server_id',$seed->server_id)->update(['processing_enabled'=>false]);
$page=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('w2-case-')->set('tableRecordsPerPage',100);
$columns=$page->instance()->getTable()->getColumns(); $records=$page->instance()->getTableRecords()->keyBy('id');
foreach ([$offTransfer,$offChange] as $d) {
    $op=$d->operations()->sole(); $line=RequestFacts::status($op); $status=DomainFacts::status($d->fresh());
    same('Under review',$line['label'],'The request reads its domain\'s word');
    $read=['Under review',rtrim((string)$line['reason'],'.').'.'.($op->state==='queued'?RequestFacts::ordersStep($line['reason']):' Check request #'.$op->id.'.')];
    same($read,[$status['label'],DomainFacts::statusLine($status)],'The heading keeps the customer word, then the request');
    same($read,[$columns['status']->record($records[$d->id])->getState(),$columns['note']->record($records[$d->id])->getState()],'The list reads as its request');
}
// POL-held: staff registration holds differ from customer presentation, including the request filter.
$pol=$row('registration_pending','register','held','staff_review_required'); $polOp=$pol->operations()->sole();
same(['On hold','warning'],array_values(array_intersect_key(RequestFacts::status($polOp),array_flip(['label','tone']))),'Staff request is On hold in amber');
same(['On hold','warning'],[DomainFacts::status($pol)['label'],DomainFacts::status($pol)['tone']],'Staff domain is On hold in amber');
same(['Under review','info'],array_slice(array_values(\Paymenter\Extensions\Servers\Domains\Presentation\StatusPill::of('registration_held')),0,2),'Customer domain stays Under review in blue');
expect(\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::status(Operation::query(),['on_hold'])->whereKey($polOp->id)->exists(),'Staff On hold filter includes registration hold');
expect(!\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::status(Operation::query(),['under_review'])->whereKey($polOp->id)->exists(),'Staff Under review filter excludes registration hold');
// A transfer held by staff uses the same status/filter and retained actor as a registration hold.
$staffTransfer=$row('transfer_pending','transfer','held','staff_review_required');$heldOp=$staffTransfer->operations()->sole();
$heldOp->update(['policy'=>['staff_actions'=>[['action'=>'hold','phase'=>'completed','actor_name'=>'Saved Operator','at'=>date(DATE_ATOM)]]]]);
$heldOp=$heldOp->fresh();
same('On hold',RequestFacts::status($heldOp)['label'],'Staff-held transfer shares On hold');
expect(\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::status(Operation::query(),['on_hold'])->whereKey($heldOp->id)->exists(),'Held-transfer filter matches presentation');
same('Put on hold by Saved Operator',Labels::requestStatus($heldOp)['reason'],'Retained hold names its recorded actor');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$staffTransfer->id])->assertSee('On hold')->assertSee('Saved Operator');
// A queued registration that Orders off stop reads On hold on its domain, as on its request and in both Status filters.
// A closed order and unfinished customer setup keep their own words.
$accounts=Account::where('server_id',$seed->server_id); $accounts->update(['processing_enabled'=>false]);
$queuedOff=$row('registration_pending','register','queued'); $queuedOp=$queuedOff->operations()->sole();
$closedOff=$row('registration_pending','register','queued'); DB::table('services')->where('id',$closedOff->service_id)->update(['status'=>'cancelled']);
$setupOff=$row('registration_pending','register','held','contact_details_required');
$word=static fn(Domain $d)=>[DomainFacts::status($d->fresh())['label'],RequestFacts::status($d->operations()->sole())['label']];
same(['On hold','On hold'],$word($queuedOff),'Queued registration with Orders off: domain and request');
same(['Under review','On hold'],$word($closedOff),'A closed order keeps its domain word');
same(['Action required','Action required'],$word($setupOff),'Customer setup keeps its word');
same('warning',DomainFacts::status($queuedOff->fresh())['tone'],'The held registration is amber');
$onHold=DomainQueries::status(Domain::whereKey([$queuedOff->id,$closedOff->id,$setupOff->id]),['on_hold'])->pluck('id')->all();
same([$queuedOff->id],$onHold,'Domain Status filter: only the stopped registration is On hold');
expect(\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::status(Operation::query(),['on_hold'])->whereKey($queuedOp->id)->exists(),'Request Status filter: the stopped registration is On hold');
$accounts->update(['processing_enabled'=>true]);
same(['Pending registration','In progress'],$word($queuedOff),'Orders on: the registration moves again');
// The list reads the Orders switches once, for its Status filter and its rows, even when a switch changes while it is built.
$flip=static function(bool $enabled) use ($seed): void {
    $armed=true;
    DB::listen(function($query) use (&$armed,$seed,$enabled): void {
        $sql=strtolower($query->sql);
        if (!$armed||!str_contains($sql,'domains_accounts')||!str_contains($sql,'processing_enabled')||str_contains($sql,'exists')) { return; }
        $armed=false; Account::where('server_id',$seed->server_id)->update(['processing_enabled'=>$enabled]);
    });
};
$parity=[]; $seen=[];
foreach (['on_hold'=>'On hold','pending_registration'=>'Pending registration'] as $key=>$label) {
    foreach ([false,true] as $enabled) {
        $accounts->update(['processing_enabled'=>!$enabled]);
        $page=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($queuedOff->domain)->filterTable('status',[$key]);
        $page->set('tableDeferredFilters',$page->get('tableFilters')); $flip($enabled); $page->call('applyTableFilters');
        $column=$page->instance()->getTable()->getColumns()['status'];$note=$page->instance()->getTable()->getColumns()['note'];
        foreach ($page->instance()->getTableRecords() as $record) {
            if ($record->id===$queuedOff->id) {
                $seen[$enabled?'on':'off'][]=$key;
                // The step reads the same switches as the word: On hold names the Orders switch, a moving registration does not.
                $step=(string)$note->record($record)->getState();
                if ((str_contains($step,'Orders are off for this account.')&&str_contains($step,'Turn them on in Registrars.'))!==($label==='On hold')) { $parity[]="$key, Orders turned ".($enabled?'on':'off').": the step reads \"$step\""; }
            }
            if (($shown=$column->record($record)->getState())!==$label) { $parity[]="$key, Orders turned ".($enabled?'on':'off').": {$record->domain} reads $shown"; }
        }
    }
}
same([],$parity,'Every row the domain Status filter keeps reads that status');
ksort($seen); same(['off'=>['pending_registration'],'on'=>['on_hold']],$seen,'The list reads the switches as they were when it started');
// Each list render reads the Orders switches once for every row's word, note and step, never once per row.
$accounts->update(['processing_enabled'=>false]);$moreOff=[$row('registration_pending','register','queued'),$row('registration_pending','register','queued')];
DB::enableQueryLog();DB::flushQueryLog();
$page=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('w2-case-')->set('tableRecordsPerPage',100);
$notes=$page->instance()->getTable()->getColumns()['note'];$built=$page->instance()->getTableRecords()->keyBy('id');
foreach([$queuedOff,...$moreOff] as $d){$notes->record($built[$d->id])->getState();}
$switches=array_values(array_filter(DB::getQueryLog(),static fn(array $q)=>str_contains($q['query'],'domains_accounts')&&str_contains($q['query'],'processing_enabled')));DB::disableQueryLog();
$perRow=array_filter(array_column($switches,'query'),static fn(string $sql)=>!str_contains($sql,'limit '.(\Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::SNAPSHOT_LIMIT+1)));
expect($switches!==[],'The list reads the Orders switches');
same([],array_values($perRow),'Three queued registrations read the Orders switches from the list snapshot, never per row');
$accounts->update(['processing_enabled'=>true]);

// A registrar-confirmed pending transfer has a scheduled read, not a staff task.
$d=$row('transfer_pending','transfer','uncertain','reconciliation_required');$op=$d->latestAcquisition;
$op->update(['started_at'=>now(),'attempt'=>['seal'=>'sealed'],'policy'=>['reconciliation'=>['transfer_status'=>'pending','reason'=>'transfer_pending','staff_alert'=>false]]]);
$d=$d->fresh();$status=DomainFacts::status($d);
expect(!$status['act']&&!str_contains((string)$status['step'],'Check request'), 'Pending transfer never asks staff to check what the worker is watching');
same('In progress',RequestFacts::status($op->fresh())['label'],'Pending transfer request is in progress');
expect(!Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::status(Operation::whereKey($op->id),['under_review'])->exists(),'Pending transfer is excluded from staff review filters');
expect(Paymenter\Extensions\Servers\Domains\Admin\Support\OperationQueries::status(Operation::whereKey($op->id),['in_progress'])->exists(),'Pending transfer is included in its displayed status filter');

$d=$row('registration_pending','register','held','staff_review_required');
expect(DomainQueries::alerting(Domain::whereKey($d->id))->exists(),'A visible staff task counts even before a saved alert is written');
$domainColumn=Livewire::actingAs($viewer)->test(ListDomains::class)->instance()->getTable()->getColumn('domain')->record($d);
$phone=(string)$domainColumn->getDescriptionBelow();
expect(str_contains($phone,'domains-phone-note')&&str_contains($phone,'On hold')&&str_contains($phone,'Check request'),'Phone summary keeps both Status and Note under the domain');
$nativeSuccessful=true; echo "Staff domain status: customer words and one next step per row.\n";
