<?php
// Staff domain page and Domains list: Registration and Billing facts, one notice, History, list columns and filters.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, PendingAdoptionResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{AcquisitionActions, DomainEditActions, DomainFacts, HoldingActions, Staff};
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, DomainSnapshot, ExpiryPrecision, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, DomainManagement, ObligationAlerts, ObservationProjection, ObservationRecords, StaffHoldingReview};
config(['settings.company_name'=>'Example Hosting']);
staffPanel(); [$held,$active,$long]=staffData(); Paymenter\Extensions\Servers\Domains\Services\PslReviewSchema::install();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
$customer=App\Models\User::create(['first_name'=>'Ada','last_name'=>'Lovelace','email'=>'ada@example.test','password'=>'synthetic-password']);
DB::table('domains_domains')->where('id',$active->id)->update(['user_id'=>$customer->id]);$active=$active->fresh();
$value=static fn(Domain $d,string $label)=>array_key_exists($label,$facts=DomainFacts::for($d->fresh()))?$facts[$label]['value']:'absent';
$sub=static fn(Domain $d,string $label)=>DomainFacts::for($d->fresh())[$label]['sub']??null;

// A hold says what is really in the way, and never a request that doesn't exist.
Illuminate\Support\Facades\Route::get('/admin/registrar-account/{account}',fn()=>'')->name('filament.admin.pages.registrar-account'); app('router')->getRoutes()->refreshNameLookups();
$processing=Account::where('server_id',$active->server_id)->value('processing_enabled'); Account::where('server_id',$active->server_id)->update(['processing_enabled'=>true]);
$switch=DomainEditActions::held($active,DomainManagement::SWITCH_OFF);
same('Changes are on hold: DNS, nameservers and contacts are Off for this registrar account. Turn them on, then reload this page.',strip_tags($switch['note'] instanceof Htmlable?$switch['note']->toHtml():$switch['note']),'The hold names the switch');
expect(str_contains($switch['held'],'DNS, nameservers and contacts')&&$switch['request']===null,'A switch blames no request');
expect(str_contains($switch['note']->toHtml(),'switch=dns#switch-dns'),'Turn them on lands on the switch');
same(['On hold until request #7 is checked.',7],[DomainEditActions::held($active,7)['held'],DomainEditActions::held($active,7)['request']],'Only the blocking request is named');
same('Wait until request #7 is checked, then add them with Edit.',DomainFacts::whileHeld(['Contacts'=>['value'=>null,'sub'=>DomainFacts::ADD_CONTACTS]],DomainEditActions::held($active,7)['first'])['Contacts']['sub'],'Contacts names the request in the way');
same(['This registrar doesn\'t support this change.',null],[DomainEditActions::held($active,'Management capability unsupported.')['held'],DomainEditActions::held($active,'Management capability unsupported.')['request']],'A known refusal keeps its words');
$unknown=DomainEditActions::held($active,null)['held'];
expect(!str_contains($unknown,'last request')&&!str_contains($unknown,'History'),'With no cause, the hold claims no request');
Account::where('server_id',$active->server_id)->update(['processing_enabled'=>$processing]);
// Progress sits under a row's own words, and a finished change stops talking.
$rows=['Contacts'=>['value'=>'Ada','sub'=>'ada@example.test'],'Nameservers'=>['value'=>null,'sub'=>'Sync to read them']];
$saving=['contacts'=>['pending'=>true,'text'=>'Saving…'],'nameservers'=>['pending'=>true,'text'=>'Saving…']];
same(['ada@example.test','Saving…'],array_column(DomainFacts::withProgress($rows,$saving),'sub'),'Progress keeps the email line and replaces a Missing cause');
same(['text'=>'Saving…','request'=>null,'waiting'=>true],DomainFacts::withProgress($rows,$saving)['Contacts']['progress'],'Progress takes its own line under the email, and the row waits');
same(false,DomainFacts::withProgress($rows,['contacts'=>['pending'=>false,'text'=>'Saved.','settled'=>false]])['Contacts']['progress']['waiting'],'A fresh result no longer waits');
same(['ada@example.test','Sync to read them'],array_column(DomainFacts::withProgress($rows,['contacts'=>['pending'=>false,'text'=>'Saved.','settled'=>true]]),'sub'),'A settled result shows nothing');
// The lock is a mark: On, a hollow Off, and while a change is out, the change with what it was.
$lockRow=['value'=>'On','sub'=>null,'progress'=>['text'=>'Sent. Checking the result…','request'=>null,'waiting'=>true]];
same(['success','gray'],[ViewDomain::lockMark($lockRow,false)['mark'],ViewDomain::lockMark([...$lockRow,'value'=>'Off'],false)['mark']],'On reads OK and Off reads as the hollow Off mark');
same(['Sent. Checking the result…','Was on.','info',false],(fn($m)=>[$m['value'],$m['sub'],$m['mark'],isset($m['progress'])])(ViewDomain::lockMark($lockRow,true)),'A waiting lock shows the change in progress and what it was, once');
same(['1 of 2 differs.','2 of 3 differ.','1 of 1 differs.'],[DomainFacts::differs(1,2),DomainFacts::differs(2,3),DomainFacts::differs(1,0)],'The glue count reads above its detail');
same('Saved.',DomainFacts::withProgress($rows,['contacts'=>['pending'=>false,'text'=>'Saved.','settled'=>false]])['Contacts']['progress']['text'],'A fresh result still shows');
// Staff read the request's own line and where to look, not the sentence written for the customer.
$stopped=['id'=>60,'pending'=>false,'text'=>'The contacts change failed. Try again. If it keeps happening, contact support.','outcome'=>'Not sent. Nothing changed at the registrar.','settled'=>false];
same(['text'=>'Not sent. Nothing changed at the registrar. Check request #60.','request'=>60,'waiting'=>false],DomainFacts::withProgress($rows,['contacts'=>$stopped],60)['Contacts']['progress'],'A stopped change names the request the head names');
same(['text'=>'Not sent. Nothing changed at the registrar.','request'=>null,'waiting'=>false],DomainFacts::withProgress($rows,['contacts'=>$stopped],61)['Contacts']['progress'],'A row names no request the head does not name');
same(['text'=>'Not sent. Nothing changed at the registrar.','request'=>null,'waiting'=>false],DomainFacts::withProgress($rows,['contacts'=>$stopped])['Contacts']['progress'],'With no request in the head, a row names none');
expect(!isset(DomainFacts::withProgress($rows,['contacts'=>['pending'=>false,'text'=>'Replaced by your latest choice.','replaced'=>true]])['Contacts']['progress']),'A replaced change says nothing to staff');
// A Missing row never points at a blocked button: it says what comes first, and the blocked button keeps its real label.
$noContacts=['Contacts'=>['value'=>null,'sub'=>DomainFacts::ADD_CONTACTS]];
same('DNS, nameservers and contacts are Off. Turn them on, then add them with Edit.',DomainFacts::whileHeld($noContacts,$switch['first'])['Contacts']['sub'],'Contacts names the Off switch before Edit');
same($rows,DomainFacts::whileHeld($rows,$switch['first']),'Contacts on file keep their email');
same(['Change','Turn off','Turn on','Change','Edit'],[ViewDomain::blockedLabel('Transfer lock',['value'=>null]),ViewDomain::blockedLabel('Transfer lock',['value'=>'On']),ViewDomain::blockedLabel('Transfer lock',['value'=>'Off']),ViewDomain::blockedLabel('Nameservers',['value'=>['ns1.example.test']]),ViewDomain::blockedLabel('Contacts',['value'=>null])],'A blocked button reads like the real one');
// Registration and Billing: one value per row, Missing as null, rows that do not apply are absent.
$f=DomainFacts::for($active);
same(['Expires','Registrar','In registrar account','Transfer lock','Registrar auto-renew','Last sync'],array_values(array_intersect(array_keys($f),DomainFacts::SECTIONS['Registration'])),'Registration rows in order');
same(['Contacts','Nameservers'],array_values(array_intersect(array_keys($f),DomainFacts::SECTIONS['Contacts and DNS'])),'Contacts and DNS rows in order');


same(false,isset($f['Glue records']),'No staff glue row without records');
$beforeGlue=$active->fresh()->observation;$glueHost='ns1.'.$active->domain;
ObservationRecords::save($active->fresh(),['glue'=>['state'=>'partial','records'=>[$glueHost=>'8.8.8.8']]],new DateTimeImmutable('2026-10-20T15:05:00+00:00'));
same([[$glueHost.' 8.8.8.8'],null,'2026-10-20T15:05:00+00:00'],[$value($active,'Glue records'),$sub($active,'Glue records'),DomainFacts::for($active->fresh())['Glue records']['asOf']??null],'Staff glue includes host, address and read time');
$gluePage=strip_tags(Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSee('Glue records')->assertSee($glueHost)->assertSee('8.8.8.8')->html());
expect(str_contains($gluePage,'As of Oct 20, 2026')&&!str_contains($gluePage,'2026-10-20T15:05'),'Read times follow the date rule');
// One record projection serves DNS, glue rows, timestamps and drift; lock/privacy retain their own reads.
$glueDnsSetting=App\Models\Server::findOrFail($active->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
DB::flushQueryLog();DB::enableQueryLog();DomainFacts::for($active->fresh());$recordQueries=DB::getQueryLog();DB::disableQueryLog();
$historyQueries=array_filter($recordQueries,fn($row)=>str_contains($row['query'],'domains_operations')&&str_contains($row['query'],'limit 101'));
expect(count($historyQueries)<=3,'Staff record facts reuse one bounded history projection');
ObservationRecords::save($active->fresh(),['dns'=>['state'=>'complete','records'=>[]],'srv'=>['state'=>'complete','records'=>[]]],new DateTimeImmutable('2026-10-20T15:05:00+00:00'));
$read=DomainFacts::for($active->fresh())['DNS records'];same(['0 records',null,'2026-10-20T15:05:00+00:00'],[$read['value'],$read['sub'],$read['asOf']??null],'No SRV says nothing, and the read time stays a time');
$glueDnsSetting->delete();
$active->fresh()->update(['observation'=>$beforeGlue]);
same([null,'Ask the customer to add them on the domain page'],[$f['Contacts']['value'],$f['Contacts']['sub']],'Unknown contacts say what to do next');
same(today()->addDays(120)->format('M j, Y'),$f['Expires']['value'],'Expires date');
same(['Synthetic registrar','Enom, Test, orders paused'],[$f['Registrar']['value'],$f['Registrar']['sub']],'New accounts start with orders paused');
DB::table('domains_accounts')->where('server_id',$active->server_id)->update(['processing_enabled'=>true]);$f=DomainFacts::for($active);
same(['Synthetic registrar','Enom, Test'],[$f['Registrar']['value'],$f['Registrar']['sub']],'Registrar account name and registrar sub-line');
same(['Yes','On','Off'],[$f['In registrar account']['value'],$f['Transfer lock']['value'],$f['Registrar auto-renew']['value']],'Synced registrar facts');
same('Off on purpose. Example Hosting renews the domain after the invoice is paid, so you are never charged by the registrar directly.',$f['Registrar auto-renew']['sub'],'Registrar auto-renew says why it is off');
same([null,'Fills in by itself within a day'],[$f['Last sync']['value'],$f['Last sync']['sub']],'Never synced is Missing with its cause');
// Core sync cannot populate on-demand DNS inventory; staff needs the record-read action in either state.
$dnsSetting=App\Models\Server::findOrFail($active->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$beforeDns=$active->fresh()->observation;$dnsMissing=[[$value($active,'DNS records'),$sub($active,'DNS records')]];
$coreObservation=$beforeDns;unset($coreObservation['followup']);$active->fresh()->update(['observation'=>$coreObservation]);
$binding=CatalogueAcceptances::binding(Account::where('server_id',$active->server_id)->firstOrFail());
ObservationProjection::save($active->fresh(),new DomainSnapshot($binding,$active->domain,owned:true,
    expiry:new DateEvidence($active->provider_expires_at->format('Y-m-d'),ExpiryPrecision::Date,'synthetic_read'),
    providerAutoRenew:false,privacy:false,locked:true,active:true),TransferStatus::Unknown,new DateTimeImmutable('2026-10-20T15:05:00+00:00'));
$dnsMissing[]=[$value($active,'DNS records'),$sub($active,'DNS records')];
same([['Not read yet',null],['Not read yet',null]],$dnsMissing,'Unread on-demand DNS is a plain state, not Missing');
$unread=DomainFacts::for($active->fresh())['DNS records'];same(null,$unread['tone'],'Unread DNS has no tone');
same('success',DomainFacts::head($active->fresh(),DomainFacts::status($active->fresh()),['DNS records'=>$unread])['tone'],'A healthy domain stays green with its DNS unread');
Auth::setUser(staffUser([Staff::VIEW,'admin.domains.manage']));same(['Not read yet','Use Sync now to read them.'],[$value($active,'DNS records'),$sub($active,'DNS records')],'Staff who can sync are told how');Auth::setUser($viewer);
same([null,'Registrar did not return it. Check the domain at the registrar.'],[$value($active,'Nameservers'),$sub($active,'Nameservers')],'Unread core facts keep their own recovery guidance');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSee('DNS records')->assertSee('Not read yet');
// Persistent record limits name the condition and a next step that changes it.
foreach ([
    'child_host_limit'=>"Missing: more than 50 child hosts to check. Ask your site admin to remove unused entries from this domain's saved discovery data, registrar nameservers and DNS templates so at most 50 child hosts remain, then use Sync now.",
    'history_limit'=>'Missing: saved record history needs a fresh read. Use Sync now to refresh DNS records.',
] as $reason=>$next) {
    ObservationRecords::hold($active->fresh(),$reason,new DateTimeImmutable('2026-10-20T15:05:00+00:00'));
    same([null,$next],[$value($active,'DNS records'),$sub($active,'DNS records')],'Missing DNS gives effective recovery for '.$reason);
    same($next,DomainFacts::recordHold($active->fresh()),'Domain notice gives effective recovery for '.$reason);
    Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSee($next);
    $active->fresh()->update(['observation'=>$coreObservation]);
}
// A later core save without nameservers retains the last current snapshot.
$snapshotAt=new DateTimeImmutable('2026-10-20T15:06:00+00:00');
$snapshotNs=['ns-latest.example.net','ns-second.example.net'];
ObservationProjection::save($active->fresh(),new DomainSnapshot($binding,$active->domain,owned:true,providerAutoRenew:false,
    nameservers:\Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence::observed($snapshotNs,'enom_getdns',$snapshotAt)),TransferStatus::Unknown,$snapshotAt);
ObservationProjection::save($active->fresh(),new DomainSnapshot($binding,$active->domain,owned:true,providerAutoRenew:false),TransferStatus::Unknown,$snapshotAt->modify('+1 minute'));
same($snapshotNs,$value($active,'Nameservers'),'Later save preserves the latest snapshot nameservers');
$active->fresh()->update(['observation'=>$beforeDns,'last_synced_at'=>null]);$dnsSetting->delete();
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
    foreach (['Expires',...DomainFacts::SECTIONS['Renewal'],...DomainFacts::SECTIONS['Billing']] as $label) { same($stable[$label]??null,$rejected[$label]??null,'Identity rejection preserves '.$label); }
    same(null,$rejected['Transfer lock']['value'],'Lock retains its existing identity check');
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
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSeeInOrder(['Transfer lock','Nameservers','ns1.example.net','ns2.example.net','ns3.example.net'])->assertSeeHtml('data-beco-tone="ok" data-beco-record="lock-'.$active->id.'"');
foreach([[],['ns1.example.net',7],['a'=>'ns1.example.net'],'ns1.example.net'] as $bad){$storeNs($active,$bad);same(null,$value($active,'Nameservers'),'Unusable stored nameservers are Missing');}
$storeNs($active,['ns1.example.net','ns2.example.net']);
// Every Missing carries its cause.
same([null,'Service #'.$active->service_id.' not found. Check the Services page.'],[$f['Service']['value'],$f['Service']['sub']],'Deleted service is Missing with its cause');
same([null,'Needs the linked service. Check the Services page.'],[$f['Renewal price']['value'],$f['Renewal price']['sub']],'Renewal price without a service names the cause');
same(['Ada Lovelace','ada@example.test'],[$f['Customer']['value'],$f['Customer']['sub']],'Customer name with email');
same(['On',null],[$f['Renewal invoices']['value'],$f['Renewal invoices']['sub']],'Renewals on');
expect(!isset($f['Auto-renew']),'Auto-renew and renewal invoices are one row');
same(today()->addDays(120-21)->format('M j, Y'),$f['Next invoice']['value'],'Next invoice from the billing calendar');
expect(!isset($f['Open invoice']),'Open invoice hidden when none');
same("The registrar's own auto-renew. Keep it off: Example Hosting renews after the customer pays.",DomainFacts::help()['Registrar auto-renew'],'Registrar auto-renew help');
DB::table('domains_domains')->where('id',$active->id)->update(['provider_expires_at'=>null]);
same([null,'Fills in by itself within a day',null,'Fills in by itself after the expiry date syncs'],[$value($active,'Expires'),$sub($active,'Expires'),$value($active,'Next invoice'),$sub($active,'Next invoice')],'Active without expiry shows Missing with causes');
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($active->domain)->assertSee('Expiry missing')->assertSee('Fills in by itself within a day');
same('Under review',DomainFacts::status($active->fresh())['label'],'Active without expiry is under review');
same('Expiry missing. Check the domain.',DomainFacts::statusLine(DomainFacts::status($active->fresh())),'Expiry missing step');
DB::table('domains_domains')->where('id',$active->id)->update(['provider_expires_at'=>today()->addDays(120)->format('Y-m-d')]);
// An inactive domain's renewal timing is not Missing: the rows do not apply.
$pending=DomainFacts::for($held);
expect(!isset($pending['Next invoice'])&&!isset($pending['Expires']),'Pending transfer hides expiry and next invoice');
same(['On hold','Old registrar needs approval. Check request #'.$held->operations()->sole()->id.'.'],[DomainFacts::status($held)['label'],DomainFacts::statusLine(DomainFacts::status($held))],'Held transfer pill and next step');
same([null,'Customer account not found. Check the Users page.'],[$value($held,'Customer'),$sub($held,'Customer')],'Deleted customer is Missing with its cause');

// Renewal settings.
$policy=$active->renewal_policy;
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode([...$policy,'owner_auto_renew'=>false]),false)]);
same(['Off','Turned off by customer'],[$value($active,'Renewal invoices'),$sub($active,'Renewal invoices')],'Owner auto-renew off');
expect(!isset(DomainFacts::for($active->fresh())['Next invoice']),'No next invoice while auto-renew is off');
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode([...$policy,'renewals_stopped'=>true]),false)]);
same(['Off','Stopped by staff'],[$value($active,'Renewal invoices'),$sub($active,'Renewal invoices')],'Staff stop');
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode($policy),false)]);

// Page: no internal facts, one notice, breadcrumb uses the name.
$page=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSuccessful()->assertSet('data',[]);
$page->assertSee('Registration')->assertSee('Billing')->assertSee('Ada Lovelace')->assertSee('Missing')->assertSee('Needs the linked service. Check the Services page.')->assertSeeHtml('domains-sheet');
foreach(['Saved facts','Assignment generation','Observation drift','Follow-up','Data retention','Server #','Domain #','Account spending holds','Preview adoption','Scheduled reads'] as $old){$page->assertDontSee($old);}
same([DomainResource::getUrl('index')=>'Domains','active.example.com'],$page->instance()->getBreadcrumbs(),'Breadcrumb names the domain');
// One head line: no box on the page, and green can't sit over rows that need work.
$page->assertDontSeeHtml('fi-callout');
$missingHead=DomainFacts::head($active->fresh(),DomainFacts::status($active->fresh()),DomainFacts::for($active->fresh()));
same(['warning','Some details are Missing. Each row says what to do.'],[$missingHead['tone'],$missingHead['step']],'Active over a Missing row turns amber and says where to look');
same(null,DomainFacts::notice($active->fresh()),'A healthy domain has no notice');
$notice=DomainFacts::notice($held);
same(['Transfer not sent: old registrar needs approval.','Check request #'.$held->operations()->sole()->id],[$notice['title'],$notice['action']['label']],'Held request notice');
$heldOperation=$held->operations()->sole();$noticeOriginal=$heldOperation->getRawOriginal();
foreach(['register'=>'Registration','transfer'=>'Transfer'] as $action=>$label){
    foreach(['enom_test_call_cap'=>'stopped by our test send check','transfer_needs_action'=>'old registrar needs approval'] as $code=>$cause){
        DB::table('domains_operations')->where('id',$heldOperation->id)->update(['action'=>$action,'safe_code'=>$code]);
        same($label.' not sent: '.$cause.'.',DomainFacts::notice($held->fresh())['title'],'Held notice preserves brand and ordinary sentence case');
        Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id])->assertSee($label.' not sent: '.$cause.'.');
    }
}
DB::table('domains_operations')->where('id',$heldOperation->id)->update(['action'=>$noticeOriginal['action'],'safe_code'=>$noticeOriginal['safe_code']]);

$heldPage=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$held->id])->assertSee('Transfer not sent: old registrar needs approval.')->assertSee('Check request #')->assertDontSeeHtml('fi-callout');
same(1,substr_count(html_entity_decode(strip_tags($heldPage->html()),ENT_QUOTES),'Transfer not sent: old registrar needs approval.'),'The reason is said once on the page');
same(1,substr_count((string)$heldPage->instance()->getSubheading(),'data-beco-tone="warn"'),'One amber mark in the head of a held domain');
// The request page head sweeps like the domain head, so it needs the same mark. A Note's step looks like the link its cell is.
$heldRequest=$held->operations()->sole()->id;
Livewire::actingAs($viewer)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$heldRequest])->assertSeeHtml('data-beco-record="request-'.$heldRequest.'"');
$note=DomainFacts::note(DomainFacts::status($held),null)['text'];
same('<span class="domains-link">Check request #'.$heldRequest.'</span>. '.e(rtrim(substr($note,0,-strlen('Check request #'.$heldRequest.'.')))),(string)DomainFacts::noteStep($note,DomainFacts::status($held)),'A Note leads with its step, which looks like a link');
same('Sync failed. Use Resume sync on the domain.',DomainFacts::noteStep('Sync failed. Use Resume sync on the domain.',DomainFacts::status($held)),'A Note with no request step stays plain');

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
Livewire::actingAs($viewer)->test(ListDomains::class)->searchTable($active->domain)->assertSee('Active')->assertSee('Sync failed')->assertSeeHtml('data-beco-tone="warn" data-beco-record="domain-'.$active->id.'"');
same('success',DomainFacts::listTone(['tone'=>'success'],null),'A healthy row stays green in the list');same('danger',DomainFacts::listTone(['tone'=>'danger'],'failed'),'A stopped sync never softens a worse status');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$active->id])->assertSee('Sync stopped after a failed check.')->assertSee('Resume sync')->assertSee('Sync failed');
Auth::setUser($staff);$stoppedHead=DomainFacts::head($active->fresh(),DomainFacts::status($active->fresh()),DomainFacts::for($active->fresh()));
same('warning',$stoppedHead['tone'],'Active over a stopped sync turns amber');
expect(str_contains((string)$stoppedHead['step'],'Sync stopped after a failed check. Use Resume sync below.')&&!str_contains((string)$stoppedHead['step'],'data-beco-tone'),'The state is the one mark');
Auth::setUser($viewer);expect(str_contains((string)DomainFacts::head($active->fresh(),DomainFacts::status($active->fresh()),DomainFacts::for($active->fresh()))['step'],'Ask staff who can resume sync.'),'Without the right the step names who can');
// One rule for the request a page names: the one that needs staff, in the head and on the rows alike, and none when nothing does.
Auth::setUser($staff);
$request=static fn(string $key,string $state,string $code,array $more=[])=>$active->operations()->create(['server_id'=>$active->server_id,'registrar_key'=>$active->registrar_key,'account_fingerprint'=>$active->account_fingerprint,
    'service_id'=>$active->service_id,'authority_generation'=>$active->authority_generation,'operation_key'=>$key,'action'=>'contacts','state'=>$state,'safe_code'=>$code,'currency'=>'USD','amount'=>'0.00','attempt'=>[],'funding'=>[],'cost'=>[],...$more]);
$headOf=static fn(?int $held=null)=>(string)DomainFacts::head($active->fresh(),DomainFacts::status($active->fresh()),DomainFacts::for($active->fresh()),$held)['step'];
$done=$request('done-ui','succeeded','readback_confirmed');
expect(!str_contains($headOf(),'Check request'),'A finished request is no step for staff');
$stuck=$request('stuck-ui','held','management_unconfirmed'); $closed=$request('closed-ui','rejected','management_retired');
ObligationAlerts::record($active->fresh(),'operation_review',time(),(int)$stuck->id);
expect(str_contains($headOf(),'Check request #'.$stuck->id.'<')&&!str_contains($headOf(),'#'.$closed->id),'The head names the request that needs staff, not the newest');
expect(!str_contains((string)$sub($active,'Registrar'),'Check'),'The head names it, so the row does not');
$second=$request('second-ui','held','management_unconfirmed');ObligationAlerts::record($active->fresh(),'operation_review',time(),(int)$second->id);
expect(str_contains($headOf(),'Check request #'.$stuck->id.'<')&&!str_contains((string)$sub($active,'Registrar'),'Check'),'With two held requests the head names one and the row names neither');
same(1,substr_count($headOf(777),'Check request #777'),'The request holding changes comes first');
ObligationAlerts::record($active->fresh(),'below_cost',time());
expect(str_ends_with((string)$sub($active,'Registrar'),'Registrar request needs a check. Check request #'.$stuck->id.'.'),'A row the head leaves says the number: '.$sub($active,'Registrar'));
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$active->id])->assertSeeHtml('>Check request #'.$stuck->id.'</a>');
// A change the system tries again by itself is no job, on the page or in the next sweep.
$claim=['seal'=>'absent','send_state'=>'definitely_unsent','management_claim'=>str_repeat('a',32)];
$stuck->update(['policy'=>['management'=>['action'=>'contacts']],'attempt'=>$claim,'started_at'=>now()]);
expect(str_contains((string)$sub($active,'Registrar'),'Check request #'.$second->id.'.'),'The next held request is named once the first clears');
DB::table('domains_obligation_alerts')->where('operation_id',$second->id)->delete();
expect(!str_contains($headOf(),'Check request')&&!str_contains((string)$sub($active,'Registrar'),'Check'),'A retry that runs by itself names no request');
$sweep=static fn(array $ops)=>ObligationAlerts::retained(['domain'=>$active->fresh(),'operations'=>collect($ops)],100);
same($sweep([]),$sweep([$stuck->fresh()]),'The sweep raises no alert for it');
$stuck->update(['attempt'=>[...$claim,'management_deferrals'=>array_fill(0,5,'2026-10-30T00:00:00+00:00')]]);
expect(str_ends_with((string)$sub($active,'Registrar'),'Check request #'.$stuck->id.'.'),'When the tries run out the same alert is back');
DB::table('domains_obligation_alerts')->where('domain_id',$active->id)->delete();
DB::table('domains_operations')->whereIn('id',[$done->id,$stuck->id,$closed->id,$second->id])->delete();
Auth::setUser($viewer);
// Nothing syncs on a domain that has ended, so a stopped sync is no step and has no button.
ObligationAlerts::record($active->fresh(),'observation_review',time());
Auth::setUser($staff); DB::table('domains_domains')->where('id',$active->id)->update(['status'=>'cancelled']);
$ended=DomainFacts::head($active->fresh(),DomainFacts::status($active->fresh()),DomainFacts::for($active->fresh()));
same('gray',$ended['tone'],'An ended domain keeps its own tone');
expect(!str_contains((string)$ended['step'],'Sync'),'An ended domain has no sync step');
expect($value($active,'Last sync')!=='Sync failed','An ended domain does not ask for a resume');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$active->id])->assertDontSee('Resume sync')->assertDontSee('Stopped until resumed');
same(null,DomainFacts::sync($active->fresh()),'The list reads the same');
DB::table('domains_domains')->where('id',$active->id)->update(['status'=>'active']);
DB::table('domains_obligation_alerts')->where('domain_id',$active->id)->delete();
Auth::setUser($viewer);
DB::table('domains_observation_work')->where('id',$work)->delete();
foreach(['history_limit'=>'Missing: saved record history needs a fresh read. Use Sync now to refresh DNS records.','ownership_drift'=>'Sync stopped: domain is not in the registrar account.',
    'glue_unavailable'=>"Couldn't read glue records. Other domain facts were saved. Use Sync now to try again.",
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
same('Enom, Test, orders paused',$sub($demo,'Registrar'),'Registrar sub-line says orders paused');
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
$history->assertSee('Search changes')->assertSee('Request #'.$active->operations()->sole()->id)->assertSee('Transfer')->assertSee('Completed')->assertSee('System')->assertDontSee('827.43');
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode([...$policy,'staff_holdings'=>[['at'=>'2026-10-21T09:30:00Z','actor_id'=>$viewer->id,'action'=>'stop','reason'=>'Customer asked by phone']]]),false)]);
WatchAudit::create(['subject_type'=>'domain','subject_id'=>$active->id,'user_id'=>$customer->id,'actor_id'=>$customer->id,'event'=>'owner_auto_renew',
    'before_revision'=>'on','after_revision'=>'off','data'=>['enabled'=>false],'created_at'=>new DateTimeImmutable('2026-10-22T08:00:00Z')]);
$history=Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$active->fresh(),'pageClass'=>ViewDomain::class]);
$history->assertSeeInOrder(['Oct 22, 2026, 8:00 AM UTC','Auto-renew turned off','Ada Lovelace','Oct 21, 2026, 9:30 AM UTC','Renewal stopped','Customer asked by phone','Synthetic Staff']);
DB::table('domains_operations')->where('domain_id',$demo->id)->delete();
Livewire::actingAs($viewer)->test(RequestsRelationManager::class,['ownerRecord'=>$demo,'pageClass'=>ViewDomain::class])->assertSee('No history yet');

// Action copy.
same('Closes the unpaid order and releases the domain name.',AcquisitionActions::DESCRIPTION,'Cancel order description');
same("Stops new renewal invoices. Invoices already sent stay payable, and renewal requests already created may still run.",HoldingActions::description('stop'),'Stop renewal description');
same('Restarts renewal invoices and removes any pending service cancellation.',HoldingActions::description('resume'),'Resume renewal description');
same(['stop'=>'Stop renewal','resume'=>'Resume renewal'],HoldingActions::LABELS,'Renewal action labels');

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

// Real database dispatch/reservation is needed: Bus::fake cannot prove unique-lock admission for two staff.
$syncQueue=config('queue');$syncCache=config('cache.default');$secondSyncStaff=staffUser($full);$reservedSync=null;$syncJob=null;
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900,'queue.connections.database.queue'=>'domains-sync-r3']);
$syncConnection=app('queue')->connection('database');
try {
    $localCacheSync=Livewire::actingAs($importer)->test(ViewDomain::class,['record'=>$active->id])->callAction('sync_now');
    same(0,$syncConnection->size(),'Local cache refuses Sync now before dispatch');
    $localCacheSync->assertNotified('Sync now needs a shared cache to prevent duplicate reads. Ask your site admin to configure Redis, database or Memcached cache, then try again.');
    \Illuminate\Support\Facades\Schema::create('cache',static function($t):void {$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
    \Illuminate\Support\Facades\Schema::create('cache_locks',static function($t):void {$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
    config(['cache.default'=>'database']);app()->forgetInstance('cache.store');
    Livewire::actingAs($importer)->test(ViewDomain::class,['record'=>$active->id])->callAction('sync_now')->assertHasNoActionErrors()->assertNotified('Sync queued');
    $reservedSync=$syncConnection->pop();expect($reservedSync!==null,'Staff A has a real reserved sync job');
    $syncJob=unserialize($reservedSync->payload()['data']['command']);
    same([$importer->id,$active->id,$active->authority_generation,$active->account_fingerprint],[$syncJob->actor,$syncJob->domain,$syncJob->generation,$syncJob->fingerprint],'Queued sync binds actor and current holding');
    Livewire::actingAs($secondSyncStaff)->test(ViewDomain::class,['record'=>$active->id])->callAction('sync_now')->assertHasNoActionErrors();
    $syncNotices=new \Filament\Notifications\Livewire\Notifications;$syncNotices->mount();
    $syncTitles=$syncNotices->notifications->map(fn($notice)=>$notice->getTitle())->values()->all();
    expect(!in_array('Sync queued',$syncTitles,true),'Second staff is not promised a silently dropped sync');
    same(["A sync was already requested. If nothing changes in 15 minutes, press Sync now again. If it still doesn't run, check the queue worker."],$syncTitles,'Second staff gets retry and worker guidance without a queued promise');
    same(1,$syncConnection->size(),'Two staff clicks retain one holding read, including its reservation');
    config(['queue.default'=>'sync','queue.connections.sync.driver'=>'sync']);
    Livewire::actingAs($importer)->test(ViewDomain::class,['record'=>$active->id])->callAction('sync_now')->assertNotified(\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::WORKER);
    same(1,$syncConnection->size(),'Sync queue refusal never falls back to inline reads');
} finally {
    if ($reservedSync) { $reservedSync->delete(); }
    if ($syncJob) { app(\Illuminate\Bus\UniqueLock::class)->release($syncJob); }
    config(['queue'=>$syncQueue,'cache.default'=>$syncCache]);app()->forgetInstance('cache.store');
}

// Open alerts: every recorded kind shows on the row it concerns with its cause. A row is Missing only when its own value is absent.
$moneyAlerts=['refund_repair'=>'Service','funds_wait_review'=>'Registrar','renewal_funds'=>'Registrar'];
foreach($moneyAlerts as $code=>$row){
    ObligationAlerts::record($active->fresh(),$code,time());
    expect(str_contains((string)$sub($active,$row),DomainFacts::alertCause($code)),$code.' appears under '.$row);
}
$moneyPage=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id]);
foreach($moneyAlerts as $code=>$row){$moneyPage->assertSee(DomainFacts::alertCause($code));}
DB::table('domains_obligation_alerts')->where('domain_id',$active->id)->whereIn('code',array_keys($moneyAlerts))->update(['resolved_at'=>time()]);
same(['acquisition_late_money'],array_values(array_diff(array_keys(ObligationAlerts::LABELS),array_keys(DomainFacts::ALERTS))),'Every alert kind has a row; late payment shows in the status and notice');
ObligationAlerts::record($active->fresh(),'native_identity_missing',time());
same([null,'Service #'.$active->service_id." not found. Check the Services page. Customer, order or service missing. The name stays reserved. Don't restore records by hand.",'danger'],[$value($active,'Service'),$sub($active,'Service'),DomainFacts::for($active->fresh())['Service']['tone']],'Missing records alert on the Service row');
ObligationAlerts::record($active->fresh(),'missing_obligation',time());
same([today()->addDays(120-21)->format('M j, Y'),'Renewal invoice missing. Check billing before expiry.','danger'],[$value($active,'Next invoice'),$sub($active,'Next invoice'),DomainFacts::for($active->fresh())['Next invoice']['tone']],'A Next invoice date stays, with the missing invoice below in danger');
ObligationAlerts::record($active->fresh(),'renewal_unpaid',time());
same(['On','Unpaid renewal invoice cancelled. Talk to the customer about renewal.','warning'],[$value($active,'Renewal invoices'),$sub($active,'Renewal invoices'),DomainFacts::for($active->fresh())['Renewal invoices']['tone']],'Review alerts keep the value and add the cause');
ObligationAlerts::record($active->fresh(),'observation_review',time());
same(['Oct 20, 2026 15:05 UTC',null],[$value($active,'Last sync'),$sub($active,'Last sync')],'A sync that is not stopped has nothing to resume');
$work=DB::table('domains_observation_work')->insertGetId(['server_id'=>$active->server_id,'account_fingerprint'=>$active->account_fingerprint,
    'domain_id'=>$active->id,'generation'=>$active->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
same('1 attempt. Stopped until resumed.',$sub($active,'Last sync'),'A sync review alert does not repeat the stopped row');
DB::table('domains_observation_work')->where('id',$work)->delete();
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSeeInOrder(['Service','Missing','Customer, order or service missing'])->assertSee('Unpaid renewal invoice cancelled');
$filter(Livewire::actingAs($viewer)->test(ListDomains::class),'alerts',true)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$held,$long]);
DB::table('domains_obligation_alerts')->update(['resolved_at'=>time()]);
same(['Service #'.$active->service_id.' not found. Check the Services page.','On'],[$sub($active,'Service'),$sub($active,'Renewal invoices')??$value($active,'Renewal invoices')],'Resolved alerts leave the rows');

// Adoption pending leaves navigation and its route opens the Under review filter.
// One click on the domain page closes what a retry cannot: an open failed notice and an uncertain email, with no resend. Nothing open, no button.
$checked=$active->fresh();
Paymenter\Extensions\Servers\Domains\Services\SalesPolicySchema::install();Paymenter\Extensions\Servers\Domains\Services\StaffAuditSchema::install();
$rights=array_map([\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::class,'permission'],['alert_review','delivery_review']);$review=staffUser([Staff::VIEW,Staff::POLICY,...$rights]);
Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionHidden('notice_review');
ObligationAlerts::record($checked->fresh(),'template_failed',time());
$uncertain=DB::table('domains_reminder_deliveries')->insertGetId(['domain_id'=>$checked->id,'delivery_key'=>hash('sha256','one-click'),'template'=>'domains_transfer_code_paid','state'=>'uncertain','created_at'=>time()]);
Livewire::actingAs(staffUser([Staff::VIEW,...$rights]))->test(ViewDomain::class,['record'=>$checked->id])->assertActionHidden('notice_review');
Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionVisible('notice_review')->assertSee('Mark reminder checked')->callAction('notice_review');
same(['reviewed',0],[DB::table('domains_reminder_deliveries')->find($uncertain)->state,DB::table('domains_obligation_alerts')->where('domain_id',$checked->id)->where('code','template_failed')->whereNull('resolved_at')->count()],'One click closes both');
Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionHidden('notice_review');
// A retained handoff needs its review button even when unreadable data removes both normal slots.
DB::table('domains_reminder_deliveries')->insert(['domain_id'=>$checked->id,'delivery_key'=>hash('sha256','fallback-review'),'template'=>'domains_transfer_code_paid','state'=>'handoff','created_at'=>time()]);
foreach (['auto-renew','alerts','neither'] as $slot) {
    if ($slot==='alerts') {
        DB::table('domains_domains')->where('id',$checked->id)->update(['renewal_policy'=>'not-encrypted']);
        ObligationAlerts::record($checked->fresh(),'template_failed',time());
    }
    if ($slot==='neither') { DB::table('domains_obligation_alerts')->where('domain_id',$checked->id)->update(['resolved_at'=>time()]); }
    $facts=DomainFacts::for($checked->fresh());
    same($slot==='auto-renew',isset($facts['Renewal invoices']),'Auto-renew slot: '.$slot);
    same($slot==='alerts',isset($facts['Open alerts']),'Open alerts slot: '.$slot);
    $page=Livewire::actingAs($review)->test(ViewDomain::class,['record'=>$checked->id])->assertActionVisible('notice_review');
    same(1,substr_count(html_entity_decode(strip_tags($page->html()),ENT_QUOTES),'Mark reminder checked'),'Review button renders once with slot: '.$slot);
}
$page->callAction('notice_review');
same('reviewed',DB::table('domains_reminder_deliveries')->where('delivery_key',hash('sha256','fallback-review'))->value('state'),'Fallback review handles the retained handoff');
same(false,PendingAdoptionResource::shouldRegisterNavigation(),'Adoption pending is not in navigation');
same(DomainResource::getUrl('index',['filters'=>['status'=>['values'=>['under_review']]]]),PendingAdoptionResource::redirectUrl(),'Adoption pending opens the Under review filter');
echo "Staff domain page and list passed.\n";$nativeSuccessful=true;
