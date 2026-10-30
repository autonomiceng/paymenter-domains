<?php
// Staff domain page and Domains list: Registration and Billing facts, one notice, History, list columns and filters.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, PendingAdoptionResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AcquisitionActions, DomainFacts, HoldingActions, Staff};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts, ObservationRecords, StaffHoldingReview};
config(['settings.company_name'=>'Example Hosting']);
staffPanel(); [$held,$active,$long]=staffData(); Paymenter\Extensions\Servers\Domains\Services\PslReviewSchema::install();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
$customer=App\Models\User::create(['first_name'=>'Ada','last_name'=>'Lovelace','email'=>'ada@example.test','password'=>'synthetic-password']);
DB::table('domains_domains')->where('id',$active->id)->update(['user_id'=>$customer->id]);$active=$active->fresh();
$value=static fn(Domain $d,string $label)=>array_key_exists($label,$facts=DomainFacts::for($d->fresh()))?$facts[$label]['value']:'absent';
$sub=static fn(Domain $d,string $label)=>DomainFacts::for($d->fresh())[$label]['sub']??null;

// Registration and Billing: one value per row, Missing as null, rows that do not apply are absent.
$f=DomainFacts::for($active);
same(['Expires','Registrar','In registrar account','Contacts','Nameservers','Transfer lock','WHOIS privacy','Registrar auto-renew','Last sync'],array_values(array_intersect(array_keys($f),DomainFacts::REGISTRATION)),'Registration rows in order');
expect(count(DomainFacts::REGISTRATION)<=9&&count(DomainFacts::BILLING)<=9,'At most nine facts per section');
same([null,'Ask the customer to add them on the domain page'],[$f['Contacts']['value'],$f['Contacts']['sub']],'Unknown contacts say what to do next');
same(today()->addDays(120)->format('M j, Y'),$f['Expires']['value'],'Expires date');
same(['Synthetic registrar','Enom · Test · orders paused'],[$f['Registrar']['value'],$f['Registrar']['sub']],'New accounts start with orders paused');
DB::table('domains_accounts')->where('server_id',$active->server_id)->update(['processing_enabled'=>true]);$f=DomainFacts::for($active);
same(['Synthetic registrar','Enom · Test'],[$f['Registrar']['value'],$f['Registrar']['sub']],'Registrar account name and registrar sub-line');
same(['Yes','On','Off','Off'],[$f['In registrar account']['value'],$f['Transfer lock']['value'],$f['WHOIS privacy']['value'],$f['Registrar auto-renew']['value']],'Synced registrar facts');
same([null,'Fills in by itself within a day'],[$f['Last sync']['value'],$f['Last sync']['sub']],'Never synced is Missing with its cause');
// Registrar facts and ownership holds belong to the current assignment only.
$factObservation=$active->fresh()->observation;
$stable=DomainFacts::for($active->fresh());
foreach (['name'=>'other.example.com','generation'=>2,'account_fingerprint'=>str_repeat('f',64),'legacy'=>null,'malformed'=>'invalid'] as $field=>$bad) {
    $saved=$factObservation;
    if ($field==='legacy') { unset($saved['facts']['generation'],$saved['facts']['account_fingerprint']); }
    elseif ($field==='malformed') { $saved['facts']=$bad; }
    else { $saved['facts'][$field]=$bad; }
    $active->fresh()->update(['observation'=>$saved]);$rejected=DomainFacts::for($active->fresh());
    foreach (['In registrar account','Registrar auto-renew'] as $label) {
        same([null,'Fills in by itself within a day'],[$rejected[$label]['value'],$rejected[$label]['sub']],'Reject registrar facts with '.$field.' for '.$label);
    }
    foreach (['Expires',...DomainFacts::BILLING] as $label) { same($stable[$label]??null,$rejected[$label]??null,'Identity rejection preserves '.$label); }
    same(null,$rejected['Transfer lock']['value'],'Lock retains its existing identity check');
    same(null,$rejected['WHOIS privacy']['value'],'Privacy retains its existing identity check');
}
$active->fresh()->update(['observation'=>$factObservation]);
ObservationRecords::hold($active->fresh(),'ownership_drift',new DateTimeImmutable('2026-10-18T12:00:00Z'));
same('No',$value($active,'In registrar account'),'Current ownership hold overrides matched owned facts');
$heldFacts=$active->fresh()->observation;
foreach (['name'=>'other.example.com','generation'=>2,'fingerprint'=>str_repeat('f',64)] as $field=>$bad) {
    $saved=$heldFacts;$saved['record_hold']['identity'][$field]=$bad;$active->fresh()->update(['observation'=>$saved]);
    same(['Yes','Off'],[$value($active,'In registrar account'),$value($active,'Registrar auto-renew')],'Stale ownership hold cannot override current facts: '.$field);
}
$active->fresh()->update(['observation'=>$factObservation]);
// Nameservers: the stored registrar readback the customer page shows, one per line in order. No extra queries.
$queries=static function(Domain $d):int{DB::enableQueryLog();DB::flushQueryLog();DomainFacts::for($d);$n=count(DB::getQueryLog());DB::disableQueryLog();return $n;};
$before=$queries($active->fresh());
same([null,'Fills in by itself within a day'],[$f['Nameservers']['value'],$f['Nameservers']['sub']],'No stored nameservers is Missing with its cause');
$storeNs=static function(Domain $d,mixed $servers){$p=$d->fresh()->observation;$p['sections']['nameservers']=['name'=>$d->domain,'generation'=>$d->authority_generation,'account_fingerprint'=>$d->account_fingerprint,'operation_key'=>'ns-read','at'=>'2026-10-20T15:05:00+00:00','facts'=>['nameservers'=>$servers]];$d->fresh()->update(['observation'=>$p]);};
$storeNs($active,['ns1.example.net','ns2.example.net','ns3.example.net']);
same(['ns1.example.net','ns2.example.net','ns3.example.net'],$value($active,'Nameservers'),'Stored nameservers in order');
same(false,DomainFacts::for($active->fresh())['Nameservers']['demo'],'Ordinary nameservers have no Demo tag');
same($before,$queries($active->fresh()),'Nameservers add no queries');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSeeInOrder(['Nameservers','ns1.example.net','ns2.example.net','ns3.example.net','Transfer lock']);
foreach([[],['ns1.example.net',7],['a'=>'ns1.example.net'],'ns1.example.net'] as $bad){$storeNs($active,$bad);same(null,$value($active,'Nameservers'),'Unusable stored nameservers are Missing');}
$storeNs($active,['ns1.example.net','ns2.example.net']);
// Every Missing carries its cause.
same([null,'Service #'.$active->service_id.' not found. Check the Services page.'],[$f['Service']['value'],$f['Service']['sub']],'Deleted service is Missing with its cause');
same([null,'Needs the linked service. Check the Services page.'],[$f['Renewal price']['value'],$f['Renewal price']['sub']],'Renewal price without a service names the cause');
same(['Ada Lovelace','ada@example.test'],[$f['Customer']['value'],$f['Customer']['sub']],'Customer name with email');
same(['On','On'],[$f['Auto-renew']['value'],$f['Renewal invoices']['value']],'Renewals on');
same(today()->addDays(120-21)->format('M j, Y'),$f['Next invoice']['value'],'Next invoice from the billing calendar');
expect(!isset($f['Open invoice']),'Open invoice hidden when none');
same("The registrar's own auto-renew. Keep it off: Example Hosting renews after the customer pays.",DomainFacts::help()['Registrar auto-renew'],'Registrar auto-renew help');
DB::table('domains_domains')->where('id',$active->id)->update(['provider_expires_at'=>null]);
same([null,'Fills in by itself within a day',null,'Fills in by itself after the expiry date syncs'],[$value($active,'Expires'),$sub($active,'Expires'),$value($active,'Next invoice'),$sub($active,'Next invoice')],'Active without expiry shows Missing with causes');
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($active->domain)->assertSee('Expiry missing')->assertSee('Fills in by itself within a day');
same('Under review',DomainFacts::status($active->fresh())['label'],'Active without expiry is under review');
same('Expiry missing · Check the domain.',DomainFacts::statusLine(DomainFacts::status($active->fresh())),'Expiry missing step');
DB::table('domains_domains')->where('id',$active->id)->update(['provider_expires_at'=>today()->addDays(120)->format('Y-m-d')]);
// An inactive domain's renewal timing is not Missing: the rows do not apply.
$pending=DomainFacts::for($held);
expect(!isset($pending['Next invoice'])&&!isset($pending['Expires']),'Pending transfer hides expiry and next invoice');
same(['Under review','Transfer on hold · Old registrar needs approval. Open request #'.$held->operations()->sole()->id.'.'],[DomainFacts::status($held)['label'],DomainFacts::statusLine(DomainFacts::status($held))],'Held transfer pill and next step');
same([null,'Customer account not found. Check the Users page.'],[$value($held,'Customer'),$sub($held,'Customer')],'Deleted customer is Missing with its cause');

// Renewal settings.
$policy=$active->renewal_policy;
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode([...$policy,'owner_auto_renew'=>false]),false)]);
same(['Off','Turned off by customer'],[$value($active,'Auto-renew'),$sub($active,'Auto-renew')],'Owner auto-renew off');
expect(!isset(DomainFacts::for($active->fresh())['Next invoice']),'No next invoice while auto-renew is off');
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode([...$policy,'renewals_stopped'=>true]),false)]);
same(['Off','Stopped by staff'],[$value($active,'Renewal invoices'),$sub($active,'Renewal invoices')],'Staff stop');
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode($policy),false)]);

// Page: no internal facts, one notice, breadcrumb uses the name.
$page=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSuccessful()->assertSet('data',[]);
$page->assertSee('Registration')->assertSee('Billing')->assertSee('Ada Lovelace')->assertSee('Missing')->assertSee('Needs the linked service. Check the Services page.')->assertSeeHtml('domains-facts');
foreach(['Saved facts','Assignment generation','Observation drift','Follow-up','Data retention','Server #','Domain #','Account spending holds','Preview adoption','Scheduled reads'] as $old){$page->assertDontSee($old);}
same([DomainResource::getUrl('index')=>'Domains','active.example.com'],$page->instance()->getBreadcrumbs(),'Breadcrumb names the domain');
same(null,DomainFacts::notice($active->fresh()),'A healthy domain has no notice');
$notice=DomainFacts::notice($held);
same(['Transfer not sent: old registrar needs approval.','Open request #'.$held->operations()->sole()->id],[$notice['title'],$notice['action']['label']],'Held request notice');
$heldOperation=$held->operations()->sole();$noticeOriginal=$heldOperation->getRawOriginal();
foreach(['register'=>'Registration','transfer'=>'Transfer'] as $action=>$label){
    foreach(['enom_test_call_cap'=>'stopped by our test send check','transfer_needs_action'=>'old registrar needs approval'] as $code=>$cause){
        DB::table('domains_operations')->where('id',$heldOperation->id)->update(['action'=>$action,'safe_code'=>$code]);
        same($label.' not sent: '.$cause.'.',DomainFacts::notice($held->fresh())['title'],'Held notice preserves brand and ordinary sentence case');
        Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id])->assertSee($label.' not sent: '.$cause.'.');
    }
}
DB::table('domains_operations')->where('id',$heldOperation->id)->update(['action'=>$noticeOriginal['action'],'safe_code'=>$noticeOriginal['safe_code']]);

Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id])->assertSee('Transfer not sent: old registrar needs approval.')->assertSee('Open request #');

// An old sweep failure must not conceal a definitely-unsent order's actual staff reason.
$unsent=Domain::create(['server_id'=>$held->server_id,'registrar_key'=>$held->registrar_key,'account_fingerprint'=>$held->account_fingerprint,
    'user_id'=>$held->user_id,'service_id'=>99991,'domain'=>'unsent-ui.example.com','sld'=>'unsent-ui','tld'=>'example.com','status'=>'transfer_pending']);
$unsent->operations()->create(['server_id'=>$unsent->server_id,'registrar_key'=>$unsent->registrar_key,'account_fingerprint'=>$unsent->account_fingerprint,
    'service_id'=>$unsent->service_id,'authority_generation'=>$unsent->authority_generation,'operation_key'=>'unsent-ui','action'=>'transfer','state'=>'held','safe_code'=>'transfer_needs_action','currency'=>'USD','amount'=>'18.00','attempt'=>[],'funding'=>[],'cost'=>[]]);
DB::table('domains_observation_work')->insert(['server_id'=>$unsent->server_id,'account_fingerprint'=>$unsent->account_fingerprint,
    'domain_id'=>$unsent->id,'generation'=>$unsent->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$unsent->id])->assertSee('Transfer not sent: old registrar needs approval.')
    ->assertDontSee('Sync stopped')->assertDontSee('Sync failed')->assertDontSee('Resume sync');
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($unsent->domain)->assertDontSee('Sync failed');

// Sync: a failed read stops immediately; the page must not claim repeated failures.
$work=DB::table('domains_observation_work')->insertGetId(['server_id'=>$active->server_id,'account_fingerprint'=>$active->account_fingerprint,
    'domain_id'=>$active->id,'generation'=>$active->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
$staff=staffUser([Staff::VIEW,'admin.domains.operations.resume_observation']);
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$active->id])
    ->assertSee('Sync stopped after a failed check.')->assertSee('1 attempt. Stopped until resumed.')->assertSee('Resume sync')->assertDontSee('repeated failures');
DB::table('domains_observation_work')->where('id',$work)->update(['safe_code'=>'read_unavailable','tries'=>5]);
same(['Sync failed','danger','5 attempts. Stopped until resumed.'],[$value($active,'Last sync'),DomainFacts::for($active->fresh())['Last sync']['tone'],$sub($active,'Last sync')],'Sync failed row');
same('Sync stopped after a failed check.',DomainFacts::notice($active->fresh())['title'],'Sync stopped notice');
DB::table('domains_domains')->where('id',$active->id)->update(['provider_expires_at'=>null]);
same('Sync failed. Use Resume sync.',$sub($active,'Expires'),'Missing expiry names the failed sync');
DB::table('domains_domains')->where('id',$active->id)->update(['provider_expires_at'=>today()->addDays(120)->format('Y-m-d')]);
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($active->domain)->assertSee('Active')->assertSee('Sync failed');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$active->id])->assertSee('Sync stopped after a failed check.')->assertSee('Resume sync')->assertSee('Sync failed');
DB::table('domains_observation_work')->where('id',$work)->delete();
foreach(['history_limit'=>'Sync stopped: DNS record history is full.','ownership_drift'=>'Sync stopped: domain is not in the registrar account.',
    'records_unavailable'=>"Sync stopped: couldn't read DNS records.",'unmapped_reason_code'=>'Sync stopped: DNS record check failed.'] as $reason=>$title){
    ObservationRecords::hold($active->fresh(),$reason,new DateTimeImmutable('2026-10-18T12:00:00Z'));
    same($title,DomainFacts::notice($active->fresh())['title'],'Record hold notice '.$reason);
    same($reason==='ownership_drift'?'No':'Yes',$value($active,'In registrar account'),'In registrar account follows the hold');
}
$p=$active->fresh()->observation;unset($p['record_hold']);$active->fresh()->update(['observation'=>$p]);
DB::table('domains_domains')->where('id',$active->id)->update(['last_synced_at'=>'2026-10-20 15:05:00']);
same('Oct 20, 2026 15:05 UTC',$value($active,'Last sync'),'Last sync time');
require_once __DIR__.'/Support/accounts.php';
$demo=Domain::create([...fixtureAccount((int)App\Models\Server::max('id')+1,'enom','demo-enom'),'domain'=>'demo-held.test','sld'=>'demo-held','tld'=>'test','user_id'=>$customer->id,'service_id'=>499,
    'status'=>'active','provider_expires_at'=>today()->addDays(200)->format('Y-m-d'),'snapshot'=>['offline_demo'=>true],'observation'=>['facts'=>[]],'renewal_policy'=>[]]);
config(['domains.enom_identity.environment'=>'test']);
same(['Paused','Demo registrar is off while Enom Test mode is on'],[$value($demo,'Last sync'),$sub($demo,'Last sync')],'Demo registrar paused under Enom Test mode');
same('Enom · Test · orders paused',$sub($demo,'Registrar'),'Registrar sub-line says orders paused');
same([null,'Fills in by itself when sync resumes'],[$value($demo,'Registrar auto-renew'),$sub($demo,'Registrar auto-renew')],'Unread registrar facts name the paused sync');
same([null,'Fills in by itself when sync resumes',false],[$value($demo,'Nameservers'),$sub($demo,'Nameservers'),DomainFacts::for($demo->fresh())['Nameservers']['demo']],'Demo without stored nameservers names the paused sync, with no Demo tag on Missing');
$storeNs($demo,['ns1.demo.test','ns2.demo.test']);
same([['ns1.demo.test','ns2.demo.test'],true],[$value($demo,'Nameservers'),DomainFacts::for($demo->fresh())['Nameservers']['demo']],'Demo nameservers keep the Demo tag');
config(['domains.enom_identity.environment'=>'live','domains.enom_writes.registration'=>false,'domains.enom_writes.renewal'=>false]);
same(null,$value($demo,'Last sync'),'Demo registrar runs when Enom Test mode is off');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$demo->id])->assertSee('Demo');
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable('demo-held.test')->assertCanSeeTableRecords([$demo])->assertSeeHtml('domains-demo-tag');
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($active->domain)->assertCanSeeTableRecords([$active])->assertDontSeeHtml('domains-demo-tag');

// Saved data that cannot be decrypted gives one danger notice.
DB::table('domains_domains')->where('id',$long->id)->update(['observation'=>'not-encrypted']);
same(["Can't read saved data. Ask your site admin to check the encryption key.",'danger'],[DomainFacts::notice($long->fresh())['title'],DomainFacts::notice($long->fresh())['tone']],'Encryption failure notice');

// History: requests, staff renewal changes and owner auto-renew changes, newest first.
$history=Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$active->fresh(),'pageClass'=>ViewDomain::class]);
$history->assertSee('History')->assertSee('Request #'.$active->operations()->sole()->id)->assertSee('Transfer')->assertSee('Completed')->assertSee('System')->assertDontSee('827.43');
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode([...$policy,'staff_holdings'=>[['at'=>'2026-10-21T09:30:00Z','actor_id'=>$viewer->id,'action'=>'stop','reason'=>'Customer asked by phone']]]),false)]);
WatchAudit::create(['subject_type'=>'domain','subject_id'=>$active->id,'user_id'=>$customer->id,'actor_id'=>$customer->id,'event'=>'owner_auto_renew',
    'before_revision'=>'on','after_revision'=>'off','data'=>['enabled'=>false],'created_at'=>new DateTimeImmutable('2026-10-22T08:00:00Z')]);
$history=Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$active->fresh(),'pageClass'=>ViewDomain::class]);
$history->assertSeeInOrder(['Oct 22, 2026 08:00 UTC','Auto-renew turned off','Ada Lovelace','Oct 21, 2026 09:30 UTC','Renewal stopped','Customer asked by phone','Synthetic Staff']);
DB::table('domains_operations')->where('domain_id',$demo->id)->delete();
Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$demo,'pageClass'=>ViewDomain::class])->assertSee('No history yet');

// Action copy.
same('Closes the unpaid order and releases the domain name.',AcquisitionActions::DESCRIPTION,'Cancel order description');
same("Stops new renewal invoices. Invoices already sent stay payable, and renewal requests already created may still run. To stop all renewals, also turn off Registrar auto-renew.",HoldingActions::description('stop'),'Stop renewal description');
same('Restarts renewal invoices and removes any pending service cancellation.',HoldingActions::description('resume'),'Resume renewal description');
same('Future renewal invoices use the price on their issue date.',HoldingActions::description('reprice'),'Edit renewal price description');
same(['reprice'=>'Edit renewal price','stop'=>'Stop renewal','resume'=>'Resume renewal'],HoldingActions::LABELS,'Renewal action labels');

// List: Status, Expires, Auto-renew and Registrar columns; Status filter uses the staff statuses.
$list=Livewire::actingAs($viewer)->test(ListDomains::class)->assertCanSeeTableRecords([$held,$active,$long]);
$list->assertTableColumnExists('auto_renew')->assertSee('Under review')->assertSee('Old registrar needs approval')->assertSee('Active')->assertSee('Ada Lovelace')->assertSee('Synthetic registrar');
foreach(['Unconfirmed','See Registrar requests','Payment review','Customer #','Sync failed'] as $old){$list->assertDontSee($old);}
$filter=static function($page,string $name,mixed $value){$page->filterTable($name,$value);return $page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters');};
$filter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['under_review'])->assertCanSeeTableRecords([$held,$long])->assertCanNotSeeTableRecords([$active]);
$filter(Livewire::actingAs($viewer)->test(ListDomains::class),'status',['active'])->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$held,$long]);
same(array_keys(Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus::SET),array_keys(DomainResource::statusOptions()),'Status filter has the customer statuses');
// Import shows only when it can finish: switched on, and the role holds every permission the import needs.
$full=[Staff::VIEW,Staff::ADOPT_PREVIEW,Staff::ADOPT,'admin.domains.manage','admin.services.update'];$importer=staffUser($full);
config(['domains.adoption.enabled'=>false]);
Livewire::actingAs($importer)->test(ListDomains::class)->assertActionHidden('adopt');
config(['domains.adoption.enabled'=>true]);
foreach(array_slice($full,1) as $missing){Livewire::actingAs(staffUser(array_values(array_diff($full,[$missing]))))->test(ListDomains::class)->assertActionHidden('adopt');}
Livewire::actingAs($importer)->test(ListDomains::class)->assertActionVisible('adopt')->assertActionHasLabel('adopt','Import domain');
config(['domains.adoption.enabled'=>false]);

// Open alerts: every recorded kind shows on the row it concerns with its cause. A row is Missing only when its own value is absent.
same(['acquisition_late_money'],array_values(array_diff(array_keys(ObligationAlerts::LABELS),array_keys(DomainFacts::ALERTS))),'Every alert kind has a row; late payment shows in the status and notice');
ObligationAlerts::record($active->fresh(),'native_identity_missing',time());
same([null,'Service #'.$active->service_id." not found. Check the Services page. · Customer, order or service missing. The name stays reserved. Don't restore records by hand.",'danger'],[$value($active,'Service'),$sub($active,'Service'),DomainFacts::for($active->fresh())['Service']['tone']],'Missing records alert on the Service row');
ObligationAlerts::record($active->fresh(),'missing_obligation',time());
same([today()->addDays(120-21)->format('M j, Y'),'Renewal invoice missing. Check billing before expiry.','danger'],[$value($active,'Next invoice'),$sub($active,'Next invoice'),DomainFacts::for($active->fresh())['Next invoice']['tone']],'A Next invoice date stays, with the missing invoice below in danger');
ObligationAlerts::record($active->fresh(),'renewal_unpaid',time());
same(['On','Unpaid renewal invoice cancelled. Talk to the customer about renewal.','warning'],[$value($active,'Renewal invoices'),$sub($active,'Renewal invoices'),DomainFacts::for($active->fresh())['Renewal invoices']['tone']],'Review alerts keep the value and add the cause');
ObligationAlerts::record($active->fresh(),'observation_review',time());
same(['Oct 20, 2026 15:05 UTC','Sync needs a check before you resume it.'],[$value($active,'Last sync'),$sub($active,'Last sync')],'Sync review alert on Last sync');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSeeInOrder(['Service','Missing','Customer, order or service missing'])->assertSee('Unpaid renewal invoice cancelled');
$filter(Livewire::actingAs($viewer)->test(ListDomains::class),'alerts',true)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$held,$long]);
DB::table('domains_obligation_alerts')->update(['resolved_at'=>time()]);
same(['Service #'.$active->service_id.' not found. Check the Services page.','On'],[$sub($active,'Service'),$sub($active,'Renewal invoices')??$value($active,'Renewal invoices')],'Resolved alerts leave the rows');

// Adoption pending leaves navigation and its route opens the Under review filter.
same(false,PendingAdoptionResource::shouldRegisterNavigation(),'Adoption pending is not in navigation');
same(DomainResource::getUrl('index',['filters'=>['status'=>['values'=>['under_review']]]]),PendingAdoptionResource::redirectUrl(),'Adoption pending opens the Under review filter');
echo "Staff domain page and list passed.\n";$nativeSuccessful=true;
