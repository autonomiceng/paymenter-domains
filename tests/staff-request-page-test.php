<?php
// Staff Registrar requests: list columns and filters, request page heading, one notice, Details facts, History and action names.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations, ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels, RegistrarFacts, RequestFacts, Staff, StaffReviewHistory};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
staffPanel(); [$held,$active,$long]=staffData();
// Notice links lead to the account page or Registrars, so their routes are registered too.
$setupPanel=\Filament\Facades\Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($setupPanel->getPath())->middleware($setupPanel->getMiddleware())
    ->group(function()use($setupPanel){Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::registerRoutes($setupPanel);Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::registerRoutes($setupPanel);});
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
[$heldOp,$doneOp,$longOp]=Operation::orderBy('id')->get()->all();

// Request status set, short reason lines that keep the real cause, and new type names.
same(['On hold','warning',"Not sent: couldn't load the registrar account's settings"],
    array_values(Labels::requestStatus(new Operation(['state'=>'held','safe_code'=>'configuration_unavailable']))),'Held request keeps its real cause');
same(['Under review','warning','Registrar has not confirmed the result'],array_values(Labels::requestStatus(new Operation(['state'=>'uncertain']))),'Unconfirmed request');
same(['In progress','info',null],array_values(Labels::requestStatus(new Operation(['state'=>'running']))),'Sending is In progress');
same(['Cancelled','gray',null],array_values(Labels::requestStatus(new Operation(['state'=>'disposed']))),'Closed by staff is Cancelled');
// The cancellation actor can differ from the person who created the request.
foreach ([(int)$held->user_id=>'customer',(int)$viewer->id=>'staff'] as $actor=>$who) {
    $retired=new Operation(['state'=>'rejected','safe_code'=>'management_retired','authorized_by'=>$viewer->id,
        'attempt'=>['management_terminal'=>['actor_id'=>$actor]]]);
    $retired->setRelation('domain',$held);
    same('Cancelled by '.$who,Labels::requestStatus($retired)['reason'],'Request history names the retirement actor');
}
same(['Put on hold by staff','Not sent: Orders off for this account','Not sent: low registrar balance','Old registrar needs approval','Missing. Check the request history.'],
    [Labels::reason('staff_review_required'),Labels::reason('processing'),Labels::reason('balance'),Labels::reason('transfer_needs_action'),Labels::reason(null)],'Reason lines');
same('Not sent: stopped by our test send check',Labels::reason('enom_test_call_cap'),'Enom test limit is one short line');
same(null,Labels::reason('refreshed'),'Internal codes have no reason line');
same(['Transfer lock','Auth code email','DNS records','Other'],[Labels::action('lock'),Labels::action('auth_code_email'),Labels::action('dns'),Labels::action('future')],'Type names');
same(['Auto Pay: charged','Auto Pay skipped: no saved payment method','Auto Pay: result missing'],
    [Labels::autoPay(['status'=>'charged']),Labels::autoPay(['status'=>'skipped','reason'=>'no_agreement']),Labels::autoPay(null)],'Auto Pay lines');
same(['recheck'=>'Check status','resume_reads'=>'Check status','resume_observation'=>'Resume sync','hold'=>'Put on hold','release'=>'Release hold',
    'disposition'=>'Close request','refund'=>'Cancel and add credit','credit'=>'Retry credit','replacement'=>'Reissue renewal invoice'],StaffOperationReview::ACTIONS,'Action names');

// List: Request, Domain, Type, Status with reason, Created, Registrar account; Status, Type and Registrar account filters.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['created_at'=>'2026-10-20 15:05:00']);
$list=Livewire::actingAs($viewer)->test(ListOperations::class)->assertSuccessful()
    ->assertSee('Request #'.$heldOp->id)->assertSee('Oct 20, 2026 15:05 UTC')->assertSee('Synthetic registrar')
    ->assertSee('On hold')->assertSee('Old registrar needs approval')->assertSee('Registrar account')
    ->assertDontSee('Awaiting reconciliation')->assertDontSee('Requests appear when');
same(['Registrar requests','List'],array_values($list->instance()->getBreadcrumbs()),'List breadcrumb in sentence case, like the title');
$filter=static function(string $name,array $value){$p=Livewire::actingAs(Auth::user())->test(ListOperations::class)->filterTable($name,$value);
    return $p->set('tableDeferredFilters',$p->get('tableFilters'))->call('applyTableFilters');};
$filter('status',['on_hold'])->assertCanSeeTableRecords([$heldOp,$longOp])->assertCanNotSeeTableRecords([$doneOp]);
$filter('status',['completed'])->assertCanSeeTableRecords([$doneOp])->assertCountTableRecords(1);
$filter('action',['transfer'])->assertCountTableRecords(3);
$filter('server_id',[$active->server_id])->assertCanSeeTableRecords([$doneOp])->assertCountTableRecords(1);

// Request #1: held on a real cause. Heading pill, one notice with its fix, Details and History.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>'configuration_unavailable','attempt'=>null]);
$manager=staffUser([Staff::VIEW,'admin.domains.manage',...array_map(StaffOperationReview::permission(...),array_keys(StaffOperationReview::ACTIONS))]); Auth::setUser($manager);
$page=Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$heldOp->id])->assertSuccessful();
$heading=(string)$page->instance()->getHeading();
expect(str_contains($heading,'Request #'.$heldOp->id)&&str_contains($heading,'On hold'),'Heading carries the request pill');
same([Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource::getUrl()=>'Registrar requests','Request #'.$heldOp->id],$page->instance()->getBreadcrumbs(),'Breadcrumbs');
// Retained expiry and actual charge in this display fixture cannot prove an unsent acquisition.
$page->assertSee("Not sent: couldn't load the registrar account's settings.")->assertSee('Open account')->assertSeeHtml(e(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$heldOp->server_id])))->assertDontSee('Release hold')->assertSee('Details')->assertSee('History')
    ->assertSee('Registrar account')->assertSee('NameSilo · Test')->assertSee('Request created')
    ->assertDontSee('Domain not found')->assertDontSee('No time recorded')->assertDontSee('Review actions')->assertDontSee('Saved evidence')->assertDontSee('Request type')->assertDontSee('Customer credit');
$page->assertActionHidden('release')->assertActionVisible('refund')->assertActionHidden('hold')->assertSee('More');
same(['recheck'],array_values(array_filter(['recheck','resume_reads'],fn($a)=>Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant($a,$heldOp->fresh()))),'One Check status at a time');
// Each account cause names its own fix; provider evidence keeps release hidden.
$links=[];
same(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$active->server_id]),Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($active)['Registrar']['url'],'Registrar row opens that account');
same(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::getUrl(),RegistrarFacts::accountUrl(0),'A missing account opens the list');
foreach(['configuration_unavailable','processing','balance','usd_balance_required','write_disabled','staff_review_required'] as $code){
    DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>$code]);
    $n=RequestFacts::notice($heldOp->fresh()); $links[$code]=[$n['link']??null,$n['link_label']??null,$n['action']??null];
}
same(['configuration_unavailable'=>['registrars','Open account',null],'processing'=>['registrars','Go to Orders switch',null],
    'balance'=>['registrars','Go to balance',null],'usd_balance_required'=>[null,null,null],
    'write_disabled'=>['registrars','Go to Setup checklist',null],'staff_review_required'=>[null,null,null]],$links,'Notice link per cause');
// Repair links depend on retained safe codes even if display copy changes.
$accountCodes=[
    'authority'=>'Open account','renewal_authority_invalid'=>'Open account','renewal_binding_changed'=>'Open account',
    'claim_authority_invalid'=>'Open account','seal_authority_invalid'=>'Open account','registration_authority_changed'=>'Open account',
    'configuration_unavailable'=>'Open account','transfer_setup_review_required'=>'Open account','enom_test_transfer_setup_required'=>'Open account','enom_test_setup_required'=>'Open account',
    'processing'=>'Go to Orders switch','balance'=>'Go to balance','funding'=>'Go to balance',
    'registrar_balance_insufficient_or_unknown'=>'Go to balance','registrar_balance_insufficient'=>'Go to balance',
    'usd_balance_required'=>null,'enom_test_transfer_usd_balance_required'=>null,
    'awaiting_acceptance'=>'Go to Setup checklist','write_disabled'=>'Go to Setup checklist','write_acceptance_required'=>'Go to Setup checklist','renewal_acceptance_required'=>'Go to Setup checklist','grace_capability_unaccepted'=>null];
foreach([...$accountCodes,'staff_review_required'=>null,'enom_test_call_cap'=>null,'nameservers_required'=>null,
    'name_unavailable'=>null,'premium_name'=>null,'order_not_supported'=>null,'registrar_price_missing'=>null,'registrar_read_failed'=>null] as $code=>$label){
    DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>$code]);
    $copy=RequestFacts::for($heldOp->fresh());$copy['reason']='Reviewed cause with different wording';
    $notice=RequestFacts::notice($heldOp->fresh(),$copy);
    same([$label?'registrars':null,$label],[$notice['link']??null,$notice['link_label']??null],'Account link uses safe code '.$code);
}
// Customer payment and renewal settings are not registrar balance problems, so they open their own record.
foreach(['renewal_invoice_unpaid'=>'invoice','renewal_funding_or_authority'=>'domain','automatic_spend_paused'=>'domain','renewal_spend_paused'=>'domain'] as $code=>$link){
    DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>$code]);
    $notice=RequestFacts::notice($heldOp->fresh());same([$link,null],[$notice['link'],$notice['link_label']??null],'Own record for '.$code);
}
// Release cannot change these answers, so the way out is the one offered.
foreach(['name_unavailable','premium_name','order_not_supported'] as $code){
    DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>$code]);
    same('refund',RequestFacts::notice($heldOp->fresh())['action'],'Cancel is offered for '.$code);
}

DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>'configuration_unavailable']);
$facts=RequestFacts::for($heldOp->fresh());
same(null,$facts['invoice']??null,'No invoice is Missing');
same('No invoice linked. No action needed.',$facts['invoice_sub']??null,'Missing invoice names its cause');

// Pending: no notice, no Sent to registrar row.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['state'=>'pending','safe_code'=>null,'started_at'=>'2026-10-20 15:06:00']);
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$heldOp->id])->assertSee('In progress')->assertSee('Sent to registrar')->assertSee('Oct 20, 2026 15:06 UTC');

// Paid and unconfirmed: the notice names the registrar and how long new orders have been paused.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['state'=>'uncertain','funding_basis'=>'paid_invoice','started_at'=>now()->subHours(73)]);
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$heldOp->id])->assertSee('NameSilo has not confirmed this transfer.')
    ->assertSee('New orders on this NameSilo account are paused until the result is known (73 hours so far).')->assertSee('Check status')
    ->assertDontSee('Release hold')->assertDontSee('Account spending holds')->assertDontSee('Paid outcome review');

// Completed: Completed pill, no notice and no More menu.
$done=Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$doneOp->id]);
expect(str_contains((string)$done->instance()->getHeading(),'Completed'),'Completed pill');
$done->assertDontSee('More')->assertDontSee('Registrar completion confirmed');

// Cancelled with credit: History names staff actions in plain words; Credit row says Credit added.
$policy=['staff_actions'=>[['key'=>'k1','action'=>'hold','phase'=>'completed','actor_id'=>1,'actor_name'=>'Ada Admin','reason'=>'Customer asked to wait','at'=>'2026-10-21T09:00:00+00:00'],
    ['key'=>'k2','action'=>'refund','phase'=>'completed','actor_id'=>1,'reason'=>'Customer cancelled','at'=>'2026-10-21T10:00:00+00:00'],['broken']]];
DB::table('domains_operations')->where('id',$longOp->id)->update(['state'=>'rejected','funding_basis'=>'paid_invoice','safe_code'=>'terminal_unsent']);
Operation::find($longOp->id)->forceFill(['policy'=>$policy,'funding'=>['refund'=>['to'=>'account_credit']]])->saveQuietly();
$rows=StaffReviewHistory::rows(Operation::find($longOp->id));
same(['Put on hold','Cancelled','Staff action'],array_column($rows,'event'),'History event names');
same(['Ada Admin','Staff'],[$rows[0]['by'],$rows[1]['by']],'By shows the stored name, else Staff');
same(['Customer asked to wait',null],[$rows[0]['sub'],$rows[2]['sub']],'Reason as the event sub-line');
same(null,$rows[2]['time'],'Unreadable entry time is Missing');
same('Credit added',RequestFacts::for(Operation::find($longOp->id))['refund'],'Credit marker reads Credit added');
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$longOp->id])->assertSee('Failed')->assertSee('Put on hold')->assertSee('Ada Admin')
    ->assertSee('Cancelled')->assertSee('Credit added')->assertDontSee('older staff review');

// Unreadable saved data: one notice with its fix, never an "unavailable" value.
DB::table('domains_operations')->where('id',$longOp->id)->update(['policy'=>'not-encrypted']);
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$longOp->id])->assertSee("Can't read saved data. Ask your site admin to check the encryption key.")->assertDontSee('unavailable');
echo "Staff request page: status set, reasons, list, notice, Details, History and action names passed.\n";
$nativeSuccessful=true;
