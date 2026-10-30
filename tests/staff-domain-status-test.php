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
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, DomainQueries, Staff};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementCorrections, ObligationAlerts};
staffPanel(); [, $seed]=staffData();
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);

Schema::disableForeignKeyConstraints();
$n=0;
$row=static function(string $status, ?string $action, ?string $state, ?string $code=null, int $days=120, array $alerts=[]) use ($seed, &$n): Domain {
    $n++; $label='w2-case-'.$n;
    $d=$seed->replicate()->fill(['domain'=>$label.'.example.com','sld'=>$label,'service_id'=>2000+$n,'status'=>$status,
        'provider_expires_at'=>today()->addDays($days)->format('Y-m-d')]);
    $d->save();
    DB::table('services')->insert(['id'=>$d->service_id,'user_id'=>$d->user_id,'order_id'=>1,'product_id'=>1,'plan_id'=>1,'status'=>'pending','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    if ($action) {
        $seed->operations()->sole()->replicate()->fill(['domain_id'=>$d->id,'service_id'=>$d->service_id,'authority_generation'=>$d->authority_generation,
            'operation_key'=>$label,'action'=>$action,'state'=>$state,'safe_code'=>$code,'attempt'=>[],'policy'=>[],'payload'=>[],'cost'=>[],'funding'=>[]])->save();
    }
    foreach ($alerts as $alert) { ObligationAlerts::record($d,$alert,time()); }
    return $d->fresh();
};
$req=static fn(Domain $d)=>'Open request #'.$d->latestAcquisition->id.'.';
$cases=[];
// The five rows Ilya saw on the demo walk (W2).
$d=$row('active','register','succeeded',null,730,['operation_review','renewal_unpaid']);
$cases['walk: active with a saved correction review']=[$d,'active','Active','Registrar request needs a check. Open the request.'];
foreach (['walk: paid registration held for staff (1)','walk: paid registration held for staff (2)'] as $name) {
    $d=$row('registration_pending','register','held','staff_review_required',120,['operation_review']);
    $cases[$name]=[$d,'pending_registration','Pending registration','Put on hold by staff. '.$req($d)];
}
$d=$row('registration_pending','register','cancelled');
$cases['walk: old cancelled request on a pending domain']=[$d,'pending_registration','Pending registration','Request cancelled, order still open. Cancel the order to release the name. '.$req($d)];
$d=$row('registration_pending','register','held','contact_details_required',120,['operation_review']);
$cases['walk: contact details hold']=[$d,'action_required','Action required','Finish setup · Contact details needed. '.$req($d)];
// Every other case the list can show.
$cases['unpaid invoice']=[$row('registration_pending','register','awaiting_payment'),'action_required','Action required','Invoice not paid · Customer has not paid the invoice. Not sent yet.'];
$cases['auth code']=[$row('transfer_pending','transfer','awaiting_code'),'action_required','Action required','Auth code needed · Customer has not added the auth code. Not sent yet.'];
$d=$row('registration_pending','register','held','nameservers_required');
$cases['nameservers hold']=[$d,'action_required','Action required','Finish setup · Not sent: nameservers missing. '.$req($d)];
$d=$row('transfer_pending','transfer','held','transfer_needs_action');
$cases['held transfer']=[$d,'under_review','Under review','Transfer on hold · Old registrar needs approval. '.$req($d)];
$d=$row('registration_pending','register','uncertain');
$cases['unconfirmed registration']=[$d,'pending_registration','Pending registration','Registrar has not confirmed the result. '.$req($d)];
$cases['queued registration']=[$row('registration_pending','register','queued'),'pending_registration','Pending registration','Registration in progress. Nothing to do yet.'];
$cases['running transfer']=[$row('transfer_pending','transfer','running'),'pending_transfer','Pending transfer','Transfer in progress. Nothing to do yet.'];
$cases['queued with an alert']=[$row('registration_pending','register','queued',null,120,['unknown_cost']),'pending_registration','Pending registration',"Cost missing. Check the registrar account's price."];
$cases['stored review']=[$row('needs_review','transfer','succeeded'),'under_review','Under review','Check the domain and its requests.'];
$cases['no expiry']=[$row('active','transfer','succeeded',null,120),'under_review','Under review','Expiry missing · Check the domain.'];
DB::table('domains_domains')->where('id',$cases['no expiry'][0]->id)->update(['provider_expires_at'=>null]);
$cases['active']=[$row('active','transfer','succeeded'),'active','Active',null];
$cases['past expiry']=[$row('active','transfer','succeeded',null,-1),'expired','Expired',null];
$cases['grace']=[$row('grace','transfer','succeeded',null,-5),'expired','Expired','In grace period'];
$cases['redemption']=[$row('redemption','transfer','succeeded',null,-40),'redemption','Redemption',null];
$cases['rejected']=[$row('registration_rejected','register','rejected','preflight_failed'),'failed','Failed','Registration did not finish'];
$cases['credited order']=[$row('registration_rejected','register','rejected','refund_approved'),'cancelled','Cancelled','Order cancelled'];
$cases['cancelled order']=[$row('cancelled','register','disposed','acquisition_cancelled_unpaid'),'cancelled','Cancelled',null];
$cases['unknown status']=[$row('something_new','transfer','succeeded'),'under_review','Under review','Status missing · Check the domain.'];
// An order reads as it does for the customer once its invoice is known. Paid orders keep only what the customer still owes.
$invoiced=static function(Domain $d, string $status, ?int $owner=null): Domain {
    $invoice=Invoice::withoutEvents(fn()=>Invoice::create(['user_id'=>$owner??$d->user_id,'currency_code'=>'USD','status'=>$status,'due_at'=>now()]));
    $d->latestAcquisition->forceFill(['invoice_id'=>$invoice->id])->saveQuietly(); return $d->fresh();
};
$unpaid='Invoice not paid · Customer has not paid the invoice. Not sent yet.';
$cases['order: unpaid invoice']=[$invoiced($row('registration_pending','register','awaiting_payment'),'pending'),'action_required','Action required',$unpaid];
$cases['order: paid invoice of another customer']=[$invoiced($row('registration_pending','register','awaiting_payment'),'paid',999),'action_required','Action required',$unpaid];
$cases['order: paid, nothing left for the customer']=[$invoiced($row('registration_pending','register','awaiting_payment'),'paid'),'pending_registration','Pending registration','Registration in progress. Nothing to do yet.'];
$cases['order: paid, auth code missing']=[$invoiced($row('transfer_pending','transfer','awaiting_payment'),'paid'),'action_required','Action required','Auth code needed · Customer has not added the auth code. Not sent yet.'];
$d=$invoiced($row('registration_pending','register','awaiting_payment'),'paid');
DB::table('domains_domains')->where('id',$d->id)->update(['registrar_key'=>'namesilo']); DB::table('domains_operations')->where('domain_id',$d->id)->update(['registrar_key'=>'namesilo']);
$cases['order: paid, setup not finished']=[$d->fresh(),'action_required','Action required','Finish setup · Customer has not finished setup. Not sent yet.'];
$d=$invoiced($row('registration_pending','register','awaiting_payment'),'pending'); DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']);
$cases['order: closed with an open invoice']=[$d,'under_review','Under review','Order cancelled · Check the domain and its requests.'];
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
$cases['registration fix still to do, unpaid renewal']=[$unfixed,'active','Active','Registrar request needs a check. Open request #'.$unfixedOriginal->id.'.'];
// A request that only looks close to done proves nothing: the alert stays live and names the request.
foreach (['queued'=>'correction queued, not sent','rejected'=>'correction retired, never sent'] as $state=>$name) {
    [$d,$original,,$correction]=$renewing($state);
    expect(ManagementCorrections::resolvedFor($correction,$original,$d),'Fixture: the renewal barrier accepts this request');
    $cases[$name]=[$d,'active','Active','Registrar request needs a check. Open request #'.$original->id.'.'];
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
$cases['alert raised again after the release']=[$d->fresh(),'pending_registration','Pending registration','Registrar request needs a check. Open request #'.$op->id.'.'];
$d=$row('registration_pending','register','queued');
ObligationAlerts::record($d,'operation_review',time(),(int)$d->latestAcquisition->id);
$cases['registration dispatch refused']=[$d,'pending_registration','Pending registration','Registrar request needs a check. '.$req($d)];
// RenewalReminders raises this on an unpaid renewal when processing or renewal authority fails. The request itself does not change.
$d=$row('active',null,null,null,730); $blocked=$request($d,'renew','awaiting_payment',$sent($d));
ObligationAlerts::record($d,'held_obligation',time(),(int)$blocked->id);
$cases['unpaid renewal blocked for staff']=[$d->fresh(),'active','Active','Renewal on hold. Check the request before expiry. Open request #'.$blocked->id.'.'];
$d=$row('active',null,null,null,730); $request($d,'renew','queued',$sent($d));
$cases['renewal on its way']=[$d->fresh(),'active','Active','Renewal in progress. Nothing to do yet.'];
$d=$row('active',null,null,null,730); $held=$request($d,'renew','held',$sent($d));
$cases['renewal on hold']=[$d->fresh(),'active','Active','Not sent. Open request #'.$held->id.'.'];

$page=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('w2-case-')->set('tableRecordsPerPage',100)->assertSuccessful();
$columns=$page->instance()->getTable()->getColumns(); $records=$page->instance()->getTableRecords()->keyBy('id');
$ids=static fn(string $key)=>DomainQueries::status(Domain::query()->where('domain','like','w2-case-%'),[$key])->orderBy('id')->pluck('id')->all();
$members=[]; $wrong=[];
foreach ($cases as $name=>[$d,$key,$label,$step]) {
    $cell=$columns['status']->record($records[$d->id]); $status=DomainFacts::status($d->fresh());
    $list=[$cell->getState(),$cell->getDescriptionBelow()];
    if ($list!==[$label,$step]) { $wrong[]=$name.': list shows "'.implode(' / ',array_filter($list)).'", want "'.implode(' / ',array_filter([$label,$step])).'"'; }
    $line=DomainFacts::statusLine($status);
    if ([$status['label'],$line]!==[$label,$step]) { $wrong[]=$name.': domain page shows "'.implode(' / ',array_filter([$status['label'],$line])).'", want the same as the list'; }
    $members[$key][]=$d->id;
}
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
$review='Registrar request needs a check. Open request #'.$movingOriginal->id.'.'; $paying='Customer has not paid the invoice. Not sent yet.';
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
$planned=$row('active',null,null,null,730); $apart($planned); $plannedRequest=$request($planned,'renew','awaiting_payment',$sent($planned));
$plannedKey=ObligationAlerts::record($planned,'held_obligation',time(),(int)$plannedRequest->id); $inspect($planned,[$plannedKey]);
$check('Alert raised by this inspection stays open',true,$open($planned,'held_obligation',$plannedRequest->id));
if ($wrong) { fwrite(STDERR,implode("\n",$wrong)."\n"); }
same([],$wrong,'Every row has one status and one next step');
expect(!isset($columns['alert']),'No separate alert column: the next step is under the status');
// The filter offers the customer's words.
same(['active'=>'Active','pending_registration'=>'Pending registration','pending_transfer'=>'Pending transfer','action_required'=>'Action required','under_review'=>'Under review',
    'expired'=>'Expired','redemption'=>'Redemption','failed'=>'Failed','cancelled'=>'Cancelled'],DomainResource::statusOptions(),'Status filter set');
foreach (array_keys(DomainResource::statusOptions()) as $key) {
    $expected=$members[$key]??[]; sort($expected);
    same($expected,$ids($key),'Status filter matches the rows: '.$key);
}
// The filter on the real list page, with its search and page size, picks the same rows.
$filtered=Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('w2-case-')->set('tableRecordsPerPage',100)->filterTable('status',['action_required']);
expect($filtered->instance()->getTableRecords()->contains('id',$cases['order: unpaid invoice'][0]->id)&&!$filtered->instance()->getTableRecords()->contains('id',$one->id),'Action required shows the unpaid order, not an Active domain');
// One answer on every surface: the list, the heading, the Registration card and the request page.
$oneView=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$one->id])->assertSee('Active')->assertDontSee('Registrar request needs a check');
same('Customer has not paid the invoice. Not sent yet.',(string)$oneView->instance()->getSubheading(),'Domain 1 heading names the open request');
same(['Awaiting payment','Customer has not paid the invoice. Not sent yet.'],array_values(array_intersect_key(RequestStatus::for($oneRenewal->state),['label'=>1,'reason'=>1])),'Request page says the same');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$two->id])->assertSee('Pending registration')->assertDontSee('Registrar request needs a check');
// Real staff work names the request and links to it, in the list and in the heading.
$link=OperationResource::getUrl('view',['record'=>$unfixedOriginal->id]);
same($link,$columns['status']->record($records[$unfixed->id])->getUrl(),'The status cell opens the request that needs the work');
expect(str_contains((string)Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$unfixed->id])->instance()->getSubheading()?->toHtml(),'href="'.e($link).'"'),'The heading links to the request that needs the work');
same(null,$columns['status']->record($records[$one->id])->getUrl(),'No request link when there is nothing to open');
// Auto-renew off near expiry reads as the customer sees it, and the filter finds it.
$soon=$row('active','transfer','succeeded',null,10); $soon->forceFill(['renewal_policy'=>['owner_auto_renew'=>false]])->saveQuietly();
same(['Action required','Auto-renew off'],array_values(array_intersect_key(DomainFacts::status($soon->fresh()),['label'=>1,'reason'=>1])),'Auto-renew off near expiry');
expect(in_array($soon->id,$ids('action_required'),true),'The status filter reads auto-renew off');
$nativeSuccessful=true; echo "Staff domain status: customer words and one next step per row.\n";
