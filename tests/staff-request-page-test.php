<?php
// Staff Registrar requests: list columns and filters, request page heading, one notice, Details facts, History and action names.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations, ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels, RequestFacts, Staff, StaffReviewHistory};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview;
staffPanel(); [$held,$active,$long]=staffData();
// Notice links lead to Registrars, so its route is registered too.
$setupPanel=\Filament\Facades\Filament::getPanel('admin');
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($setupPanel->getPath())->middleware($setupPanel->getMiddleware())
    ->group(fn()=>Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::registerRoutes($setupPanel));
app('router')->getRoutes()->refreshNameLookups(); app('router')->getRoutes()->refreshActionLookups();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
[$heldOp,$doneOp,$longOp]=Operation::orderBy('id')->get()->all();

// Request status set, short reason lines that keep the real cause, and new type names.
same(['Under review','warning',"Not sent: couldn't load the registrar account's settings"],
    array_values(Labels::requestStatus(new Operation(['state'=>'held','safe_code'=>'configuration_unavailable']))),'Held request keeps its real cause');
same(['Under review','warning','Registrar has not confirmed the result'],array_values(Labels::requestStatus(new Operation(['state'=>'uncertain']))),'Unconfirmed request');
same(['In progress','info',null],array_values(Labels::requestStatus(new Operation(['state'=>'running']))),'Sending is In progress');
same(['Cancelled','gray',null],array_values(Labels::requestStatus(new Operation(['state'=>'disposed']))),'Closed by staff is Cancelled');
same(['Put on hold by staff','Not sent: orders paused','Not sent: low registrar balance','Old registrar needs approval','Missing'],
    [Labels::reason('staff_review_required'),Labels::reason('processing'),Labels::reason('balance'),Labels::reason('transfer_needs_action'),Labels::reason(null)],'Reason lines');
same('Not sent: stopped by our TEST send check',Labels::reason('enom_test_call_cap'),'Enom test limit is one short line');
same(null,Labels::reason('refreshed'),'Internal codes have no reason line');
same(['Transfer lock','Auth code email','DNS records','Other'],[Labels::action('lock'),Labels::action('auth_code_email'),Labels::action('dns'),Labels::action('future')],'Type names');
same(['Auto Pay: charged','Auto Pay skipped: no saved card','Auto Pay: result missing'],
    [Labels::autoPay(['status'=>'charged']),Labels::autoPay(['status'=>'skipped','reason'=>'no_agreement']),Labels::autoPay(null)],'Auto Pay lines');
same(['recheck'=>'Check status','resume_reads'=>'Check status','resume_observation'=>'Resume sync','hold'=>'Put on hold','release'=>'Release hold',
    'disposition'=>'Close request','refund'=>'Cancel and refund','credit'=>'Retry refund','replacement'=>'Reissue renewal invoice'],StaffOperationReview::ACTIONS,'Action names');

// List: Request, Domain, Type, Status with reason, Created, Registrar account; Status, Type and Registrar account filters.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['created_at'=>'2026-10-20 15:05:00']);
$list=Livewire::actingAs($viewer)->test(ListOperations::class)->assertSuccessful()
    ->assertSee('Request #'.$heldOp->id)->assertSee('Oct 20, 2026 15:05 UTC')->assertSee('Synthetic registrar')
    ->assertSee('Under review')->assertSee('Old registrar needs approval')->assertSee('Registrar account')
    ->assertDontSee('Awaiting reconciliation')->assertDontSee('Requests appear when');
same(['Registrar requests','List'],array_values($list->instance()->getBreadcrumbs()),'List breadcrumb in sentence case, like the title');
$filter=static function(string $name,array $value){$p=Livewire::actingAs(Auth::user())->test(ListOperations::class)->filterTable($name,$value);
    return $p->set('tableDeferredFilters',$p->get('tableFilters'))->call('applyTableFilters');};
$filter('status',['under_review'])->assertCanSeeTableRecords([$heldOp,$longOp])->assertCanNotSeeTableRecords([$doneOp]);
$filter('status',['completed'])->assertCanSeeTableRecords([$doneOp])->assertCountTableRecords(1);
$filter('action',['transfer'])->assertCountTableRecords(3);
$filter('server_id',[$active->server_id])->assertCanSeeTableRecords([$doneOp])->assertCountTableRecords(1);

// Request #1: held on a real cause. Heading pill, one notice with its fix, Details and History.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>'configuration_unavailable','attempt'=>null]);
$manager=staffUser([Staff::VIEW,'admin.domains.manage',...array_map(StaffOperationReview::permission(...),array_keys(StaffOperationReview::ACTIONS))]); Auth::setUser($manager);
$page=Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$heldOp->id])->assertSuccessful();
$heading=(string)$page->instance()->getHeading();
expect(str_contains($heading,'Request #'.$heldOp->id)&&str_contains($heading,'Under review'),'Heading carries the request pill');
same([Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource::getUrl()=>'Registrar requests','Request #'.$heldOp->id],$page->instance()->getBreadcrumbs(),'Breadcrumbs');
// Retained expiry and actual charge in this display fixture cannot prove an unsent acquisition.
$page->assertSee("Not sent: couldn't load the registrar account's settings.")->assertSee('Open Registrars')->assertDontSee('Release hold')->assertSee('Details')->assertSee('History')
    ->assertSee('Registrar account')->assertSee('NameSilo · Test')->assertSee('Request created')
    ->assertDontSee('Domain not found')->assertDontSee('No time recorded')->assertDontSee('Review actions')->assertDontSee('Saved evidence')->assertDontSee('Request type')->assertDontSee('Customer credit');
$page->assertActionHidden('release')->assertActionVisible('refund')->assertActionHidden('hold')->assertSee('More');
same(['recheck'],array_values(array_filter(['recheck','resume_reads'],fn($a)=>Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant($a,$heldOp->fresh()))),'One Check status at a time');
// Each account cause names its own fix; provider evidence keeps release hidden.
$links=[];
foreach(['configuration_unavailable','processing','balance','usd_balance_required','write_disabled','staff_review_required'] as $code){
    DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>$code]);
    $n=RequestFacts::notice($heldOp->fresh()); $links[$code]=[$n['link']??null,$n['link_label']??null,$n['action']??null];
}
same(['configuration_unavailable'=>['registrars','Open Registrars',null],'processing'=>['registrars','Resume orders',null],
    'balance'=>['registrars','Check balance',null],'usd_balance_required'=>['registrars','Check balance',null],
    'write_disabled'=>['registrars','Turn on',null],'staff_review_required'=>[null,null,null]],$links,'Notice link per cause');
// Repair links depend on retained safe codes even if display copy changes.
$accountCodes=[
    'authority'=>'Open Registrars','renewal_authority_invalid'=>'Open Registrars','renewal_binding_changed'=>'Open Registrars',
    'claim_authority_invalid'=>'Open Registrars','seal_authority_invalid'=>'Open Registrars','registration_authority_changed'=>'Open Registrars',
    'configuration_unavailable'=>'Open Registrars','transfer_setup_review_required'=>'Open Registrars','enom_test_transfer_setup_required'=>'Open Registrars','enom_test_setup_required'=>'Open Registrars',
    'processing'=>'Resume orders','automatic_spend_paused'=>'Resume orders','balance'=>'Check balance','funding'=>'Check balance',
    'renewal_funding_or_authority'=>'Check balance','registrar_balance_insufficient_or_unknown'=>'Check balance',
    'usd_balance_required'=>'Check balance','enom_test_transfer_usd_balance_required'=>'Check balance',
    'awaiting_acceptance'=>'Turn on','write_disabled'=>'Turn on','write_acceptance_required'=>'Turn on','renewal_acceptance_required'=>'Turn on','grace_capability_unaccepted'=>'Turn on'];
foreach([...$accountCodes,'staff_review_required'=>null,'enom_test_call_cap'=>null,'nameservers_required'=>null] as $code=>$label){
    DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>$code]);
    $copy=RequestFacts::for($heldOp->fresh());$copy['reason']='Reviewed cause with different wording';
    $notice=RequestFacts::notice($heldOp->fresh(),$copy);
    same([$label?'registrars':null,$label],[$notice['link']??null,$notice['link_label']??null],'Account link uses safe code '.$code);
}

DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>'configuration_unavailable']);
$facts=RequestFacts::for($heldOp->fresh());
same(null,$facts['invoice']??null,'No invoice is Missing');
same('No invoice linked',$facts['invoice_sub']??null,'Missing invoice names its cause');

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

// Cancelled and refunded: History names staff actions in plain words; Refund row says Refunded.
$policy=['staff_actions'=>[['key'=>'k1','action'=>'hold','phase'=>'completed','actor_id'=>1,'actor_name'=>'Ada Admin','reason'=>'Customer asked to wait','at'=>'2026-10-21T09:00:00+00:00'],
    ['key'=>'k2','action'=>'refund','phase'=>'completed','actor_id'=>1,'reason'=>'Customer cancelled','at'=>'2026-10-21T10:00:00+00:00'],['broken']]];
DB::table('domains_operations')->where('id',$longOp->id)->update(['state'=>'rejected','funding_basis'=>'paid_invoice','safe_code'=>'terminal_unsent']);
Operation::find($longOp->id)->forceFill(['policy'=>$policy,'funding'=>['refund'=>['to'=>'account_credit']]])->saveQuietly();
$rows=StaffReviewHistory::rows(Operation::find($longOp->id));
same(['Put on hold','Cancelled and refunded','Staff action'],array_column($rows,'event'),'History event names');
same(['Ada Admin','Staff'],[$rows[0]['by'],$rows[1]['by']],'By shows the stored name, else Staff');
same(['Customer asked to wait',null],[$rows[0]['sub'],$rows[2]['sub']],'Reason as the event sub-line');
same(null,$rows[2]['time'],'Unreadable entry time is Missing');
same('Refunded',RequestFacts::for(Operation::find($longOp->id))['refund'],'Credit marker reads Refunded');
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$longOp->id])->assertSee('Failed')->assertSee('Put on hold')->assertSee('Ada Admin')
    ->assertSee('Cancelled and refunded')->assertSee('Refunded')->assertDontSee('older staff review');

// Unreadable saved data: one notice with its fix, never an "unavailable" value.
DB::table('domains_operations')->where('id',$longOp->id)->update(['policy'=>'not-encrypted']);
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$longOp->id])->assertSee("Can't read saved data. Check the app encryption key.")->assertDontSee('unavailable');
echo "Staff request page: status set, reasons, list, notice, Details, History and action names passed.\n";
$nativeSuccessful=true;
