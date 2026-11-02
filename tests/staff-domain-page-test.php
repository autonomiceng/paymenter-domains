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
use Paymenter\Extensions\Servers\Domains\Data\{DateEvidence, DomainSnapshot, ExpiryPrecision, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, WatchAudit};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, ObligationAlerts, ObservationProjection, ObservationRecords, StaffHoldingReview};
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
expect(count(DomainFacts::REGISTRATION)<=11&&count(DomainFacts::BILLING)<=9,'At most eleven Registration and nine Billing facts, including optional glue');
same(false,isset($f['Glue records']),'No staff glue row without records');
$beforeGlue=$active->fresh()->observation;$glueHost='ns1.'.$active->domain;
ObservationRecords::save($active->fresh(),['glue'=>['state'=>'partial','records'=>[$glueHost=>'8.8.8.8']]],new DateTimeImmutable('2026-10-20T15:05:00+00:00'));
same([[$glueHost.' 8.8.8.8'],'As of 2026-10-20T15:05:00+00:00'],[$value($active,'Glue records'),$sub($active,'Glue records')],'Staff glue includes host, address and read time');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSee('Glue records')->assertSee($glueHost)->assertSee('8.8.8.8')->assertSee('As of 2026-10-20T15:05:00+00:00');
// One record projection serves DNS, glue rows, timestamps and drift; lock/privacy retain their own reads.
$glueDnsSetting=App\Models\Server::findOrFail($active->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
DB::flushQueryLog();DB::enableQueryLog();DomainFacts::for($active->fresh());$recordQueries=DB::getQueryLog();DB::disableQueryLog();
$historyQueries=array_filter($recordQueries,fn($row)=>str_contains($row['query'],'domains_operations')&&str_contains($row['query'],'limit 101'));
expect(count($historyQueries)<=3,'Staff record facts reuse one bounded history projection');
$glueDnsSetting->delete();
$active->fresh()->update(['observation'=>$beforeGlue]);
same([null,'Ask the customer to add them on the domain page'],[$f['Contacts']['value'],$f['Contacts']['sub']],'Unknown contacts say what to do next');
same(today()->addDays(120)->format('M j, Y'),$f['Expires']['value'],'Expires date');
same(['Synthetic registrar','Enom · Test · orders paused'],[$f['Registrar']['value'],$f['Registrar']['sub']],'New accounts start with orders paused');
DB::table('domains_accounts')->where('server_id',$active->server_id)->update(['processing_enabled'=>true]);$f=DomainFacts::for($active);
same(['Synthetic registrar','Enom · Test'],[$f['Registrar']['value'],$f['Registrar']['sub']],'Registrar account name and registrar sub-line');
same(['Yes','On','Off','Off'],[$f['In registrar account']['value'],$f['Transfer lock']['value'],$f['WHOIS privacy']['value'],$f['Registrar auto-renew']['value']],'Synced registrar facts');
same([null,'Fills in by itself within a day'],[$f['Last sync']['value'],$f['Last sync']['sub']],'Never synced is Missing with its cause');
// Privacy is presentation-only: both registrars hide it without changing stored facts.
foreach (['enom','namesilo'] as $registrar) {
    DB::table('domains_domains')->where('id',$active->id)->update(['registrar_key'=>$registrar]);
    Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertDontSee('WHOIS privacy');
    same('Off',DomainFacts::for($active->fresh())['WHOIS privacy']['value'],'Hidden privacy remains in local facts for '.$registrar);
}
DB::table('domains_domains')->where('id',$active->id)->update(['registrar_key'=>'enom']);
// Core sync cannot populate on-demand DNS inventory; staff needs the record-read action in either state.
$dnsSetting=App\Models\Server::findOrFail($active->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$beforeDns=$active->fresh()->observation;$dnsMissing=[[$value($active,'DNS records'),$sub($active,'DNS records')]];
$coreObservation=$beforeDns;unset($coreObservation['followup']);$active->fresh()->update(['observation'=>$coreObservation]);
$binding=CatalogueAcceptances::binding(Account::where('server_id',$active->server_id)->firstOrFail());
ObservationProjection::save($active->fresh(),new DomainSnapshot($binding,$active->domain,owned:true,
    expiry:new DateEvidence($active->provider_expires_at->format('Y-m-d'),ExpiryPrecision::Date,'synthetic_read'),
    providerAutoRenew:false,privacy:false,locked:true,active:true),TransferStatus::Unknown,new DateTimeImmutable('2026-10-20T15:05:00+00:00'));
$dnsMissing[]=[$value($active,'DNS records'),$sub($active,'DNS records')];
same([[null,'Use Sync now to read DNS records.'],[null,'Use Sync now to read DNS records.']],$dnsMissing,'Unread on-demand DNS names Sync now before and after a good core sync');
same([null,'Registrar did not return it. Check the domain at the registrar.'],[$value($active,'Nameservers'),$sub($active,'Nameservers')],'Unread core facts keep their own recovery guidance');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSee('DNS records')->assertSee('Use Sync now to read DNS records.');
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
same(['On',null],[$f['Renewal invoices']['value'],$f['Renewal invoices']['sub']],'Renewals on');
expect(!isset($f['Auto-renew']),'Auto-renew and renewal invoices are one row');
same(today()->addDays(120-21)->format('M j, Y'),$f['Next invoice']['value'],'Next invoice from the billing calendar');
expect(!isset($f['Open invoice']),'Open invoice hidden when none');
same("Off on purpose. Example Hosting renews the domain after the invoice is paid, so you are never charged by the registrar directly.",DomainFacts::help()['Registrar auto-renew'],'Registrar auto-renew help');
// "Off on purpose" belongs only on a registrar auto-renew that was seen off. On or not yet read gets no such help.
$autoRenew=$active->fresh()->observation;
foreach (['off'=>[false,'Off',true],'on'=>[true,'On',false],'unknown'=>[null,null,false]] as $case=>[$seen,$shown,$help]) {
    $saved=$autoRenew;if ($seen===null) { unset($saved['facts']['auto_renew']); } else { $saved['facts']['auto_renew']=$seen; }
    $active->fresh()->update(['observation'=>$saved]);$fact=DomainFacts::for($active->fresh())['Registrar auto-renew'];
    same([$shown,$help?DomainFacts::help()['Registrar auto-renew']:null],[$fact['value'],$fact['help']],'Registrar auto-renew help only when seen off: '.$case);
    $page=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id]);
    $help?$page->assertSee('Off on purpose'):$page->assertDontSee('Off on purpose');
}
$active->fresh()->update(['observation'=>$autoRenew]);
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
same(['Off','Turned off by customer'],[$value($active,'Renewal invoices'),$sub($active,'Renewal invoices')],'Owner auto-renew off');
expect(!isset(DomainFacts::for($active->fresh())['Next invoice']),'No next invoice while auto-renew is off');
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode([...$policy,'renewals_stopped'=>true]),false)]);
same(['Off','Stopped by staff'],[$value($active,'Renewal invoices'),$sub($active,'Renewal invoices')],'Staff stop');
DB::table('domains_domains')->where('id',$active->id)->update(['renewal_policy'=>encrypt(json_encode($policy),false)]);

// Page: no internal facts, one notice, breadcrumb uses the name.
$page=Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$active->id])->assertSuccessful()->assertSet('data',[]);
$page->assertDontSee('WHOIS privacy')->assertSee('Renewal invoices')->assertSee('Registration')->assertSee('Billing')->assertSee('Ada Lovelace')->assertSee('Missing')->assertSee('Needs the linked service. Check the Services page.')->assertSeeHtml('domains-facts');
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
same("Stops new renewal invoices. Invoices already sent stay payable, and renewal requests already created may still run. To stop all renewals, also turn off auto-renew at the registrar.",HoldingActions::description('stop'),'Stop renewal description');
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
same([null,'Service #'.$active->service_id." not found. Check the Services page. · Customer, order or service missing. The name stays reserved. Don't restore records by hand.",'danger'],[$value($active,'Service'),$sub($active,'Service'),DomainFacts::for($active->fresh())['Service']['tone']],'Missing records alert on the Service row');
ObligationAlerts::record($active->fresh(),'missing_obligation',time());
same([today()->addDays(120-21)->format('M j, Y'),'Renewal invoice missing. Check billing before expiry.','danger'],[$value($active,'Next invoice'),$sub($active,'Next invoice'),DomainFacts::for($active->fresh())['Next invoice']['tone']],'A Next invoice date stays, with the missing invoice below in danger');
ObligationAlerts::record($active->fresh(),'renewal_unpaid',time());
same(['On','Unpaid renewal invoice cancelled. Talk to the customer about renewal.','warning'],[$value($active,'Renewal invoices'),$sub($active,'Renewal invoices'),DomainFacts::for($active->fresh())['Renewal invoices']['tone']],'Review alerts keep the value and add the cause');
ObligationAlerts::record($active->fresh(),'observation_review',time());
same(['Oct 20, 2026 15:05 UTC',null],[$value($active,'Last sync'),$sub($active,'Last sync')],'A sync that is not stopped has nothing to resume');
$work=DB::table('domains_observation_work')->insertGetId(['server_id'=>$active->server_id,'account_fingerprint'=>$active->account_fingerprint,
    'domain_id'=>$active->id,'generation'=>$active->authority_generation,'due_at'=>0,'safe_code'=>'read_failed','tries'=>1]);
same('1 attempt. Stopped until resumed. · Sync stopped. Use Resume sync.',$sub($active,'Last sync'),'Sync review alert on Last sync');
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
