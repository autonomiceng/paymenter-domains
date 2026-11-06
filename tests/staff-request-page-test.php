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
same(['Under review','warning',"Not sent: couldn't load the registrar account's settings"],
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
    'disposition'=>'Close request','refund'=>'Cancel and refund','credit'=>'Retry refund','replacement'=>'Reissue renewal invoice'],StaffOperationReview::ACTIONS,'Action names');

// List: Request, Domain, Type, Status, Note, Created, Registrar account; Status, Type and Registrar account filters. One-line rows.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['created_at'=>'2026-10-20 15:05:00']);
$list=Livewire::actingAs($viewer)->test(ListOperations::class)->assertSuccessful()
    ->assertSee('Request #'.$heldOp->id)->assertSeeHtml('2026-10-20T15:05:00+00:00')->assertSee('Synthetic registrar')
    ->assertSee('Under review')->assertSee('Old registrar needs approval')->assertSee('Registrar account')->assertSee('Note')->assertSeeHtml('placeholder="Search domain"')
    ->assertDontSee('Awaiting reconciliation')->assertDontSee('Requests appear when');
same(['Registrar requests','List'],array_values($list->instance()->getBreadcrumbs()),'List breadcrumb in sentence case, like the title');
staffTableStandard($list,'Search domain',['domain.domain','state','note']);staffRowsStandard($list);
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
expect(str_contains((string)$page->instance()->getHeading(),'Request #'.$heldOp->id)&&str_contains($page->instance()->getSubheading()->toHtml(),'Under review'),'The line under the heading carries the request state');
same([Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource::getUrl()=>'Registrar requests','Request #'.$heldOp->id],$page->instance()->getBreadcrumbs(),'Breadcrumbs');
// Retained expiry and actual charge in this display fixture cannot prove an unsent acquisition.
$page->assertSee("Not sent: couldn't load the registrar account's settings.")->assertSee('Open account')->assertSeeHtml(e(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$heldOp->server_id])))->assertDontSee('Release hold')->assertSee('Details')->assertSee('History')
    ->assertSee('Registrar account')->assertSee('NameSilo, Test')->assertSee('Request created')
    ->assertDontSee('Domain not found')->assertDontSee('No time recorded')->assertDontSee('Review actions')->assertDontSee('Saved evidence')->assertDontSee('Request type')->assertDontSee('Customer credit');
$page->assertActionHidden('release')->assertActionVisible('refund')->assertActionHidden('hold')->assertSee('More');
same(['recheck'],array_values(array_filter(['recheck','resume_reads'],fn($a)=>Paymenter\Extensions\Servers\Domains\Admin\Support\OperationActions::relevant($a,$heldOp->fresh()))),'One Check status at a time');
// Each account cause names its own fix; provider evidence keeps release hidden.
$links=[];
same(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$active->server_id]),Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::for($active)['Registrar']['url'],'Registrar row opens that account');
same(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::getUrl(),RegistrarFacts::accountUrl(0),'A missing account opens the list');
same(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::getUrl(['account'=>$active->server_id,'switch'=>'orders']).'#switch-orders',RegistrarFacts::accountUrl($active->server_id,'orders'),'A switch link carries its anchor');
same(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::getUrl(),RegistrarFacts::accountUrl(0,'orders'),'A missing account has no switch to land on');
same(['Go to Orders switch'=>'orders','Turn them on'=>'dns'],RequestFacts::SWITCH_LINKS,'Each link that promises a switch names it');
same(['Orders','DNS, nameservers and contacts'],array_values(array_intersect_key(RegistrarFacts::SWITCHES,array_flip(RequestFacts::SWITCH_LINKS))),'Each named switch is a row on the account page');
foreach(['configuration_unavailable','processing','balance','usd_balance_required','write_disabled','staff_review_required'] as $code){
    DB::table('domains_operations')->where('id',$heldOp->id)->update(['safe_code'=>$code]);
    $n=RequestFacts::notice($heldOp->fresh()); $links[$code]=[$n['link']??null,$n['link_label']??null,$n['action']??null];
}
same(['configuration_unavailable'=>['registrars','Open account',null],'processing'=>['registrars','Go to Orders switch',null],
    'balance'=>['registrars','Go to balance',null],'usd_balance_required'=>[null,null,null],
    'write_disabled'=>['registrars','Go to System checks',null],'staff_review_required'=>[null,null,null]],$links,'Notice link per cause');
// Repair links depend on retained safe codes even if display copy changes.
$accountCodes=[
    'authority'=>'Open account','renewal_authority_invalid'=>'Open account','renewal_binding_changed'=>'Open account',
    'claim_authority_invalid'=>'Open account','seal_authority_invalid'=>'Open account','registration_authority_changed'=>'Open account',
    'configuration_unavailable'=>'Open account','transfer_setup_review_required'=>'Open account','enom_test_transfer_setup_required'=>'Open account','enom_test_setup_required'=>'Open account',
    'processing'=>'Go to Orders switch','balance'=>'Go to balance','funding'=>'Go to balance',
    'registrar_balance_insufficient_or_unknown'=>'Go to balance','registrar_balance_insufficient'=>'Go to balance',
    'usd_balance_required'=>null,'enom_test_transfer_usd_balance_required'=>null,
    'awaiting_acceptance'=>'Go to System checks','write_disabled'=>'Go to System checks','write_acceptance_required'=>'Go to System checks','renewal_acceptance_required'=>'Go to System checks','grace_capability_unaccepted'=>null];
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
// A free change has no money rows, finished or not.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['action'=>'nameservers','state'=>'rejected','funding_basis'=>'none']);
same([],array_intersect_key(RequestFacts::for($heldOp->fresh()),['invoice'=>1,'cost'=>1,'refund'=>1]),'No money rows on a free change');
$queued=$heldOp->fresh()->forceFill(['state'=>'queued','attempt'=>[],'started_at'=>null,'provider_order_id'=>null,'cost'=>[]]);
Auth::setUser(staffUser([Staff::VIEW,Staff::COSTS]));
same([],array_intersect_key(RequestFacts::for($queued),['invoice'=>1,'cost'=>1,'refund'=>1]),'No money rows on a free change before it is sent');
Auth::setUser($manager);
DB::table('domains_operations')->where('id',$heldOp->id)->update(['action'=>'transfer','state'=>'held','funding_basis'=>$heldOp->funding_basis]);

// Pending: no notice, no Sent to registrar row.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['state'=>'pending','safe_code'=>null,'started_at'=>'2026-10-20 15:06:00']);
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$heldOp->id])->assertSee('In progress')->assertSee('Sent to registrar')->assertSeeHtml('2026-10-20T15:06:00+00:00');

// Paid and unconfirmed: the notice names the registrar and how long new orders have been paused.
DB::table('domains_operations')->where('id',$heldOp->id)->update(['state'=>'uncertain','funding_basis'=>'paid_invoice','started_at'=>now()->subHours(73)]);
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$heldOp->id])->assertSee('NameSilo has not confirmed this transfer.')
    ->assertSee('Paid domain changes on this NameSilo account wait until the result is known (73 hours so far).')->assertSee('Check status')
    ->assertDontSee('Release hold')->assertDontSee('Account spending holds')->assertDontSee('Paid outcome review');
// ui-r19: a send that may have gone out is said once, in History; Details has no second dated row for it.
$keptRow=(array)DB::table('domains_operations')->where('id',$heldOp->id)->first(['attempt','updated_at']);
Operation::find($heldOp->id)->forceFill(['attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent']])->saveQuietly();
$unsure=Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$heldOp->id])->assertSee('Request created')->html();
same(1,substr_count($unsure,'Registrar has not confirmed the result'),'The unconfirmed result is said once on the request page');unset($unsure);
DB::table('domains_operations')->where('id',$heldOp->id)->update($keptRow);unset($keptRow);

// Completed: Completed pill, no notice and no More menu.
$done=Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$doneOp->id]);
expect(str_contains($done->instance()->getSubheading()->toHtml(),'Completed'),'Completed pill');
$done->assertDontSee('More')->assertDontSee('Registrar completion confirmed');

// Cancelled with credit: History names staff actions in plain words; Credit row says Credit added.
$manager->role->update(['permissions'=>[...$manager->role->permissions,Staff::COSTS]]);
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

// Gateway return facts keep their title but direct staff to the invoice/gateway, never to support.
foreach(['review'=>'Refund needs a check','pending'=>'Refund processing','refunded'=>'Refunded'] as $result=>$title){
    $row=Operation::find($longOp->id);$row->forceFill(['funding'=>[
        'refund'=>['to'=>'original_payment','bridge_minor'=>0,'allocations'=>[['key'=>'staff-return','amount_minor'=>100]]],
        'receipt_return_results'=>['staff-return'=>['state'=>$result]]]])->saveQuietly();
    $facts=RequestFacts::for($row);same($title,$facts['refund'],'Staff keeps the gateway return title');
    expect(!str_contains($facts['refund_sub'],'Contact support')&&!str_contains($facts['refund_sub'],'We are returning'),'Staff guidance does not address the customer');
}

// A change that never reached the registrar says what happens next, since its page has no button.
$claim=['seal'=>'absent','send_state'=>'definitely_unsent','management_claim'=>str_repeat('a',32)];
$unsent=$doneOp->fresh()->forceFill(['state'=>'held','safe_code'=>'management_unconfirmed','action'=>'contacts','provider_order_id'=>null,'confirmed_expires_at'=>null,'funding'=>[],'started_at'=>now(),
    'policy'=>['management'=>['action'=>'contacts']],'attempt'=>$claim]);
$told=static fn(Operation $op)=>array_intersect_key(RequestFacts::notice($op,['reason'=>'Registrar has not confirmed the result']),['title'=>1,'body'=>1,'tone'=>1,'link'=>1,'link_label'=>1]);
$title='Not sent. Nothing changed at the registrar.';
same(['title'=>$title,'body'=>'DNS, nameservers and contacts are Off for this registrar account. Turn them on and we try again by ourselves.','tone'=>'warning','link'=>'registrars','link_label'=>'Turn them on'],$told($unsent),'The switch is the next step');
$elsewhere=(clone $unsent)->forceFill(['registrar_key'=>'namesilo']);
same(['title'=>$title,'body'=>'We try again by ourselves. There is nothing to do yet.','tone'=>'info'],$told($elsewhere),'A retry that runs by itself asks nothing');
$spent=(clone $elsewhere)->forceFill(['attempt'=>[...$claim,'management_deferrals'=>array_fill(0,5,'2026-10-30T00:00:00+00:00')]]);
same(['title'=>$title,'body'=>'We won\'t try again. If the change is still needed, make it again on the domain page.','tone'=>'warning','link'=>'domain'],$told($spent),'Spent tries send staff to the domain');
// One line says what a stopped or ended change did, from its real state, for staff and customers alike.
$line=static fn(Operation $op)=>Labels::requestStatus($op)['reason'];
same($title,$line($unsent),'Held and never sent reads Not sent');
// 90f1ac46 added current Orders/retry guidance to recoverable changes; the raw outcome above still proves no send.
$rail=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::row($unsent,$unsent->domain,true);
same('Not sent. Orders are off for this account.',$rail['status']['reason'],'The rail names the current Orders hold');
same($rail['status']['reason'],$rail['sub'],'The rail detail keeps the same current hold');
same($rail['status'],RequestFacts::status($unsent,true,$unsent->domain),'The request page and rail read the same');
$retryAccount=RequestFacts::account($unsent)['account'];$processing=$retryAccount->processing_enabled;
try{
    DB::table('domains_accounts')->where('id',$retryAccount->id)->update(['processing_enabled'=>true]);
    $retryRail=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::row($unsent,$unsent->domain,false);
    same('Not sent. It will retry automatically.',$retryRail['status']['reason'],'Orders on names the automatic retry');
    same($retryRail['status']['reason'],$retryRail['sub'],'The rail detail keeps the retry step');
}finally{DB::table('domains_accounts')->where('id',$retryAccount->id)->update(['processing_enabled'=>$processing]);}
$sealed=(clone $unsent)->forceFill(['attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent']]);
same('Registrar has not confirmed the result',$line($sealed),'Only a change that may have gone out is unknown');
same('Under review',Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::row((clone $sealed)->forceFill(['state'=>'succeeded','completed_at'=>now()]),$unsent->domain,true)['status']['label'],'A change the registrar has not read back reads Under review on the rail, as in customer History');
same('Under review',RequestFacts::status((clone $sealed)->forceFill(['state'=>'succeeded','completed_at'=>now()])->setRelation('domain',$unsent->domain))['label'],'The request list and page read it the same');
$failedUnsent=(clone $unsent)->forceFill(['state'=>'rejected']);
same($title,$line($failedUnsent),'A failed change that never went out says so');
same('We couldn’t make this change.',$line((clone $sealed)->forceFill(['state'=>'rejected'])),'A failed row never says the result is unknown');
// Orders off stop all queued work. A queued request that waits on them is held for staff: it reads Under review, as its domain does, on its page, in the list and on the rail, with the hold in its reason.
$queued=$heldOp->fresh()->forceFill(['state'=>'queued','safe_code'=>null,'invoice_id'=>null,'funding_basis'=>'non_billable_management']);
$orders=Paymenter\Extensions\Servers\Domains\Models\Account::where('account_fingerprint',$heldOp->account_fingerprint);
$orders->update(['processing_enabled'=>true]);
same('In progress',RequestFacts::status($queued)['label'],'Queued with Orders on is moving');
$orders->update(['processing_enabled'=>false]);
// One map: for every request kind, a request in a state its domain also has reads the domain's word, on the page and list (RequestFacts) and on the rail.
$words=[]; $domainWord=fn(string $state)=>Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::for($state)['label'];
foreach(['register','transfer','renew','nameservers','contacts','lock','privacy_purchase','dns'] as $kind){
    foreach(array_keys(Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::STATES) as $state){
        if(!isset(Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::STATES[$state])){continue;}
        $op=$heldOp->fresh()->forceFill(['action'=>$kind,'state'=>$state]);
        foreach(['page'=>RequestFacts::status($op,true,$heldOp->domain)['label'],'rail'=>Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::row($op,$heldOp->domain,true)['status']['label']] as $where=>$word){
            if($word!==($kind==='register'&&$state==='held'?'On hold':$domainWord($state))){$words[]="$kind $state ($where): request \"$word\", domain \"{$domainWord($state)}\"";}
        }
    }
    $op=(clone $queued)->forceFill(['action'=>$kind]);
    if(($word=RequestFacts::status($op)['label'])!==($kind==='register'?'On hold':$domainWord('held'))){$words[]="$kind queued, Orders off: request \"$word\", domain \"{$domainWord('held')}\"";}
}
same([],$words,'A request reads the same word as its domain for the same state, for every kind');
same(['Under review','warning','Not sent. Orders are off for this account.'],array_values(RequestFacts::status($queued)),'Queued with Orders off reads Under review, amber, with the hold');
same('Under review',Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::row($queued,$heldOp->domain,true)['status']['label'],'The rail reads the same');
same('In progress',RequestFacts::status($queued,false)['label'],'A list that knows Orders are on skips the check');
// The Status filter reads the same map: a queued request on an account with Orders off is in Under review, not In progress.
DB::table('domains_operations')->where('id',$queued->id)->update(['state'=>'queued','safe_code'=>null]);
$filter('status',['under_review'])->assertCanSeeTableRecords([$queued]);
$filter('status',['in_progress'])->assertCanNotSeeTableRecords([$queued]);
$orders->update(['processing_enabled'=>true]);
$filter('status',['in_progress'])->assertCanSeeTableRecords([$queued]);
$filter('status',['under_review'])->assertCanNotSeeTableRecords([$queued]);
// Held for setup reads Action required, as its domain does, with the next step it already shows.
$setup=[]; $next=[];
foreach(['register','transfer','renew','nameservers','contacts','lock','privacy_purchase','dns'] as $kind){
    foreach(['contact_details_required','nameservers_required'] as $code){
        $op=$heldOp->fresh()->forceFill(['action'=>$kind,'state'=>'held','safe_code'=>$code]);
        $page=RequestFacts::status($op,true,$heldOp->domain);
        foreach(['page'=>$page['label'],'rail'=>Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::row($op,$heldOp->domain,true)['status']['label']] as $where=>$word){
            if($word!==$domainWord('setup_required')){$setup[]="$kind $code ($where): request \"$word\", domain \"{$domainWord('setup_required')}\"";}
        }
        if(!is_string($page['reason'])||$page['reason']===''||$page['reason']==='Status missing'){$next[]="$kind $code";}
    }
}
same([],$setup,'A request held for setup reads Action required, as its domain does, for every kind');
same([],$next,'A request held for setup keeps its next step');
DB::table('domains_operations')->where('id',$queued->id)->update(['state'=>'held','safe_code'=>'contact_details_required']);
$filter('status',['action_required'])->assertCanSeeTableRecords([$queued]);
$filter('status',['under_review'])->assertCanNotSeeTableRecords([$queued]);
// Unreadable saved data: one notice with its fix, never an "unavailable" value.
DB::table('domains_operations')->where('id',$longOp->id)->update(['policy'=>'not-encrypted']);
Livewire::actingAs($manager)->test(ViewOperation::class,['record'=>$longOp->id])->assertSee("Can't read saved data. Ask your site admin to check the encryption key.")->assertDontSee('unavailable');
echo "Staff request page: status set, reasons, list, notice, Details, History and action names passed.\n";
$nativeSuccessful=true;
