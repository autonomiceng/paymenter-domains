<?php
// Native locking, durable engine, controller ownership and real Blade cannot be exercised by a protocol unit test.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\{ManagementForms,ManagementProgress,ManagementReadRecovery,ObservationProjection,OwnedDomains,OwnerPorts};
[$d,$driver]=$holding(placeholderComFixtureName('pending-control-placeholder.com'),
    \Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence::observed(['ns1.example.net','ns2.example.net'],'enom_getdns',$clock->now()));
$op=$submit($d,$driver,'lock',['locked'=>false]);
same($op->id,$submit($d,$driver,'lock',['locked'=>false])->id,'A new nonce merges the same unfinished desired state');
$latest=$submit($d,$driver,'lock',['locked'=>true]);
same('management_retired',$op->fresh()->safe_code,'A different unsent choice retires the older request');
same('rejected',$management->run($op->id,$driver)->state,'Retired delivery never sends');same([],$state->calls,'No old send');
same('succeeded',$management->run($latest->id,$driver)->state,'The last unsent choice wins');
// Existing deployments can already contain two unsent choices from before per-setting merging.
Cache::flush();$older=$submit($d,$driver,'lock',['locked'=>false]);$legacy=$older->replicate();
$legacy->fill(['operation_key'=>'manage:'.$d->id.':'.$key(),'payload'=>['locked'=>true],
    'policy'=>['management'=>[...$older->policy['management'],'input'=>['locked'=>true]]]]);$legacy->save();
same($legacy->id,$submit($d,$driver,'lock',['locked'=>true])->id,'A fresh nonce merges the latest legacy choice');
same('management_retired',$older->fresh()->safe_code,'Merging also retires older unsent legacy choices');
$before=count($state->calls);same('rejected',$management->run($older->id,$driver)->state,'Legacy delivery cannot reverse the newer choice');
same($before,count($state->calls),'Retired legacy delivery makes no calls');
same('succeeded',$management->run($legacy->id,$driver)->state,'The retained choice confirms');
Cache::flush();$op=$submit($d,$driver,'lock',['locked'=>false]);$state->fault='SetRegLock';
same('uncertain',$management->run($op->id,$driver)->state,'Sealed unclear reply remains uncertain');
same($op->id,$submit($d,$driver,'lock',['locked'=>true])->id,'A different desired state returns the unfinished sealed request');
same(1,count(array_filter($state->calls,fn($call)=>$call[0]==='SetRegLock'&&$call[1]['UnlockRegistrar']===1)),'No repeated unlock');
$actor=staffUser([\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW,'admin.domains.manage']);
same($op->id,$management->request($actor,$d->id,'lock',['locked'=>false],$key(),$driver)->id,'Staff and customer share the existing request');
// A legacy newer unsent row must neither outrank nor mask the older sealed request.
$legacy=$op->replicate();$legacy->fill(['operation_key'=>'manage:'.$d->id.':'.$key(),'state'=>'queued','attempt'=>[],
    'safe_code'=>null,'started_at'=>null,'completed_at'=>null,'lease_expires_at'=>null,'payload'=>['locked'=>true],
    'policy'=>['management'=>[...$op->policy['management'],'input'=>['locked'=>true]]]]);$legacy->save();
same($op->id,$submit($d,$driver,'lock',['locked'=>true])->id,'The sealed legacy request retains the setting');
same('management_retired',$legacy->fresh()->safe_code,'Newer unsent legacy debt is retired behind a sealed request');
$ports=new OwnerPorts(fn($b)=>$driver);$owners=new OwnedDomains($clock,$ports,$management);$controller=new OwnedDomainController($owners);
storefrontViews();$request=Request::create('/domains/'.$d->id);$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('request')->session());
$response=$controller->status($request,$d->id);$rows=$response->getData(true);
same($op->id,$rows['lock']['id'],'Status returns the durable operation');expect($rows['lock']['pending'],'Status remains pending');
expect(!str_contains($response->getContent(),'operation_key')&&!str_contains($response->getContent(),'payload'),'Status has no private operation data');
app()->instance(OwnerPorts::class,$ports);app()->instance(OwnedDomains::class,$owners);app()->instance(\Paymenter\Extensions\Servers\Domains\Services\DomainManagement::class,$management);
$before=$state->calls;$controller->status($request,$d->id);same($before,$state->calls,'Polling never calls the registrar');
$html=$controller->show($request,$d->id)->render();
expect(str_contains($html,'data-management-pending="lock"'),'Reload keeps the pending control');
expect(str_contains($html,'<li class="domains-row domains-row--pending" id="lock">'),'The waiting row carries the pending tint');
expect(!str_contains($html,'<li class="domains-row domains-row--pending" id="nameservers">'),'Only the waiting row is tinted');
expect(!str_contains($html,'id="form-lock"'),'The unfinished setting has no second change control');
$foreign=Request::create('/');$foreign->setUserResolver(fn()=>commerceUser());
try{$controller->status($foreign,$d->id);expect(false,'Foreign status must fail');}catch(Illuminate\Database\Eloquent\ModelNotFoundException){}
$old=$op->created_at;$op->update(['created_at'=>$old->copy()->subSeconds(119)]);
expect(!ManagementProgress::for($d)['lock']['slow'],'A change stays fast before the current two-minute boundary');
$op->update(['created_at'=>$old->copy()->subSeconds(120)]);
expect(ManagementProgress::for($d)['lock']['slow'],'Slow status survives reload');
$state->fault='';$state->locked=false;same('succeeded',$management->reconcile($op->id,$driver)->state,'Readback completes the original operation');
expect(!ManagementProgress::for($d)['lock']['pending'],'Completion stops polling');
same($op->id,ManagementProgress::for($d)['lock']['id'],'Completion keeps the confirmed retained result instead of newer retired history');
$html=$controller->show($request,$d->id)->render();expect(str_contains($html,'id="form-lock"'),'Completion restores the action');
expect(str_contains($html,'Saved.'),'A fresh result shows');
$op->update(['updated_at'=>now()->subSeconds(ManagementProgress::SETTLED_SECONDS+1)]);same(true,ManagementProgress::for($d)['lock']['settled'],'A finished change settles');
$html=$controller->show($request,$d->id)->render();expect(!str_contains($html,'Saved.'),'Saved shows only while it is news');
// A local failed attempt may retry safely, while a lost reply may not send again.
Cache::flush();$state->fault='refused';$op=$submit($d,$driver,'lock',['locked'=>true]);same('rejected',$management->run($op->id,$driver)->state,'A provider refusal finishes');
expect(!ManagementProgress::for($d)['lock']['pending'],'Definite failure stops polling');
same('The registrar did not take this change.',ManagementProgress::for($d)['lock']['outcome'],'Staff read what the registrar did');
$state->fault='';Cache::flush();$retry=$submit($d,$driver,'lock',['locked'=>true]);expect($retry->id!==$op->id,'Definite failure permits an explicit retry');
same('succeeded',$management->run($retry->id,$driver)->state,'Retry confirms');
// Staff and customer controls share the same stored operation, including slow and finished states.
Cache::flush();$pending=$submit($d,$driver,'lock',['locked'=>false]);
staffPanel();$staffPage=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id]);
expect(str_contains($staffPage->html(),'wire:poll.2s'),'Staff pending controls poll every two seconds');
$before=$state->calls;$staffPage->call('pollManagement');same($before,$state->calls,'Staff polling reads stored state only');
$pendingAt=$pending->created_at;
$pending->update(['created_at'=>$pendingAt->copy()->subSeconds(119)]);
$staffPage->call('pollManagement');expect(str_contains($staffPage->html(),'wire:poll.2s'),'Staff controls keep normal polling inside two minutes');
$pending->update(['created_at'=>$pendingAt->copy()->subSeconds(120)]);
$staffPage->call('pollManagement');expect(str_contains($staffPage->html(),'wire:poll.10s'),'Staff slow controls poll every ten seconds');
// Background polling stops after its own bound. The change stays unresolved and shown as waiting.
$followed=$clock->instant;$bound=15*60;$clock->instant=$followed->modify('+'.($bound-1).' seconds');
$staffPage->call('pollManagement');expect(str_contains($staffPage->html(),'wire:poll.10s'),'Staff keep following a waiting change inside the bound');
$clock->instant=$followed->modify('+'.$bound.' seconds');
$before=$state->calls;$staffPage->call('pollManagement');same($before,$state->calls,'Stopping the poll calls no registrar');
expect(!str_contains($staffPage->html(),'wire:poll.'),'Staff background polling stops at its bound');
expect(str_contains($staffPage->html(),'A change is still waiting. Reload this page to check it again.'),'Staff are told the change still waits');
expect(str_contains($staffPage->html(),'This is taking longer than usual.'),'The row keeps its waiting words');
expect(ManagementProgress::for($d)['lock']['pending'],'Stopping the poll leaves the change unresolved');
// Sync now keeps its own window while the change still waits.
$syncKey=(new \Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords(0,(int)$d->id,$d->authority_generation,$d->account_fingerprint))->uniqueId().':pending';
Cache::put($syncKey,true,60);$staffPage->call('pollManagement');
expect(str_contains($staffPage->html(),'wire:poll.10s')&&str_contains($staffPage->html(),'Sync in progress;'),'A running sync keeps the page polling past the change bound');
Cache::forget($syncKey);$staffPage->call('pollManagement');expect(!str_contains($staffPage->html(),'wire:poll.'),'The change bound still holds after the sync');
// Reopening the page follows the waiting change again.
$reopened=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id]);
expect(str_contains($reopened->html(),'wire:poll.10s'),'A reopened page follows the waiting change again');
$clock->instant=$followed;
same('succeeded',$management->run($pending->id,$driver)->state,'Staff sees the same confirmed result');
$staffPage->call('pollManagement');expect(!str_contains($staffPage->html(),'wire:poll.'),'Staff completion stops polling');
// Forms register work after commit and after the response, without queue-worker availability.
Cache::flush();$forms=new ManagementForms($management,$ports);$before=count($state->calls);
$forms->submit($user,$d->fresh(),'lock',['locked'=>'1'],$key());
same($before,count($state->calls),'Saving performs no provider I/O');
app()->terminate();same('succeeded',$d->operations()->where('action','lock')->orderByDesc('id')->first()->state,'After-response callback starts the saved operation');
// A damaged encrypted row must stay visible without hiding another setting or reviving an older result.
$damaged=$d->operations()->where('action','lock')->orderByDesc('id')->first();
Cache::flush();$nameservers=$submit($d,$driver,'nameservers',['nameservers'=>['ns3.example.net','ns4.example.net']]);
foreach (['attempt','policy'] as $field) {
    $raw=$damaged->getRawOriginal($field);$before=$state->calls;
    DB::table('domains_operations')->where('id',$damaged->id)->update([$field=>'unreadable synthetic ciphertext']);
    try {
        $rows=$controller->status($request,$d->id)->getData(true);
        same($damaged->id,$rows['lock']['id'],'Unreadable newest change never falls back to an older result');
        expect($rows['lock']['unreadable']??false,'Unreadable change has its own status');
        expect(!$rows['lock']['pending'],'Unreadable change does not poll forever');
        same($nameservers->id,$rows['nameservers']['id'],'Other settings remain available');
        expect($rows['nameservers']['pending'],'Other settings retain their pending state');
        expect(str_contains($controller->show($request,$d->id)->render(),$rows['lock']['text']),'Owner page shows the unreadable change');
        $page=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id]);
        expect(str_contains($page->html(),$rows['lock']['text']),'Staff page shows the unreadable change');
        same($before,$state->calls,'Unreadable status and pages perform no registrar calls');
    } finally { DB::table('domains_operations')->where('id',$damaged->id)->update([$field=>$raw]); }
}
// Unreadable financial history is not a management change; unreadable changes remain visible.
$registration=$d->operations()->where('action','register')->firstOrFail();
$renewal=$registration->replicate();$renewal->fill(['action'=>'renew','operation_key'=>'renew:history:'.$key(),'invoice_id'=>null,'invoice_item_id'=>null]);$renewal->save();
foreach ([$registration,$renewal,$nameservers] as $entry) {
    $raw=$entry->getRawOriginal('policy');
    DB::table('domains_operations')->where('id',$entry->id)->update(['policy'=>'unreadable synthetic history']);
    try {
        $unreadable=array_values(array_filter($owners->present($d->fresh(),false)->progress,fn($row)=>$row['state']==='Unreadable'));
        same($entry->action==='nameservers'?['Nameservers']:[],array_column($unreadable,'label'),'Only unreadable management actions enter change history');
    } finally { DB::table('domains_operations')->where('id',$entry->id)->update(['policy'=>$raw]); }
}
DB::table('domains_operations')->where('id',$renewal->id)->delete();
$doc=new DOMDocument();@$doc->loadHTML($controller->show($request,$d->id)->render());$xpath=new DOMXPath($doc);
$value=$xpath->query('//*[@id="nameservers"]/*[contains(@class,"domains-row__v")]')->item(0);
expect($value!==null && str_contains($value->textContent,'ns1.example.net') && str_contains($value->textContent,'ns2.example.net'),'Pending row keeps both confirmed nameservers visible');
$status=$xpath->query('.//*[@data-management-pending="nameservers"]',$value)->item(0);
expect($status!==null && !str_contains($status->textContent,'ns1.example.net'),'Polling updates only the status, preserving the confirmed nameservers');
// A later registrar read can differ without changing the completed request's result.
$pendingNameservers=$owners->present($d->fresh());
same('succeeded',$management->run($nameservers->id,$driver)->state,'Nameserver request completes');
foreach ([$pendingNameservers,$owners->present($d->fresh())] as $dto) {
    $paused=new ($dto::class)(...[...get_object_vars($dto),'syncPaused'=>true]);
    $row=storefrontDom(view('domains::partials.owner-management',['domain'=>$paused,'caps'=>['nameservers']])->render())->query('//*[@id="nameservers"]')->item(0);
    foreach ($dto->management['nameservers']['current'] as $server) {
        expect(!str_contains($row->textContent,$server),'Paused sync hides stored nameservers even while pending');
    }
    if ($dto->management['nameservers']['progress']['pending']) {
        expect(str_contains($row->textContent,$dto->management['nameservers']['progress']['text']),'Paused sync retains the pending status');
    }
}
$saved=ManagementReadRecovery::latest($nameservers->fresh());$observation=$d->fresh()->observation;
$later=(new DateTimeImmutable($saved['at']))->modify('+1 minute')->format(DATE_ATOM);
$outside=['ns5.example.net','ns6.example.net'];
$section=ObservationProjection::section($d,[...$saved,'at'=>$later,'facts'=>['nameservers'=>$outside]]);
$d->update(['observation'=>[...$observation,'sections'=>[...$observation['sections'],'nameservers'=>$section]]]);
$before=$state->calls;$changed='Nameservers changed after this request. Check them and change them if needed.';
$rows=$controller->status($request,$d->id)->getData(true);
same($changed,$rows['nameservers']['text'],'Newer differing nameservers must not be labelled Saved');
$html=$controller->show($request,$d->id)->render();
expect(str_contains($html,$changed) && str_contains($html,$outside[0]),'Owner sees current nameservers and what to do next');
$page=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id]);
expect(str_contains($page->html(),$changed) && str_contains($page->html(),$outside[0]),'Staff sees the same current nameservers and next step');
same('succeeded',$nameservers->fresh()->state,'Later observation keeps the request completed');
same($before,$state->calls,'Comparing stored results performs no registrar calls');
foreach ([['at'=>$saved['at']],['facts'=>['nameservers'=>['NS4.EXAMPLE.NET.','ns3.example.net']]],['generation'=>$d->authority_generation+1],['at'=>'invalid']] as $unchanged) {
    $d->update(['observation'=>[...$observation,'sections'=>[...$observation['sections'],'nameservers'=>[...$section,...$unchanged]]]]);
    same('Saved.',$controller->status($request,$d->id)->getData(true)['nameservers']['text'],'Only a newer valid differing read changes the status');
}
$d->update(['observation'=>$observation]);
// Exhausted, definitely-unsent holds are resolved debt, so later requests can accumulate behind a stalled queued setting.
Cache::flush();$queuedLock=$submit($d,$driver,'lock',['locked'=>false]);
$recoverable=$nameservers->replicate();$recoverable->fill(['operation_key'=>'manage:'.$d->id.':'.$key(),'state'=>'held','safe_code'=>'management_unconfirmed',
    'started_at'=>$clock->now(),'attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent','management_claim'=>str_repeat('b',32),
        'management_deferrals'=>array_fill(0,4,$clock->now()->format(DATE_ATOM))]]);$recoverable->save();
for($i=0;$i<100;$i++) {
    $held=$nameservers->replicate();$held->fill(['operation_key'=>'manage:'.$d->id.':'.$key(),'state'=>'held','safe_code'=>'management_unconfirmed',
        'started_at'=>$clock->now(),'attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent','management_claim'=>str_repeat('a',32),
            'management_deferrals'=>array_fill(0,5,$clock->now()->format(DATE_ATOM))]]);$held->save();
}
same([],$management->available($user,$d->fresh(),$driver,['lock']),'Overflow hides advisory controls');
same($queuedLock->id,$submit($d,$driver,'lock',['locked'=>false])->id,'Advisory overflow does not bound the request path');
$hydrated=[];$measuring=true;
\Paymenter\Extensions\Servers\Domains\Models\Operation::retrieved(static function($op)use(&$hydrated,&$measuring):void{if($measuring){$hydrated[]=$op->id;}});
$rows=$controller->status($request,$d->id)->getData(true);$measuring=false;
same($queuedLock->id,$rows['lock']['id']??null,'Older pending lock survives more than 100 newer matching holds');
expect($rows['lock']['pending'],'History overflow preserves the pending lock status');
same($recoverable->id,$rows['nameservers']['id']??null,'Exhausted holds cannot hide an older recoverable hold for the same setting');
expect($rows['nameservers']['pending'],'The older held change remains pending');
expect(count($hydrated)<=21,'A status poll hydrates at most three candidates per management action, independent of held history');
echo 'Status poll hydrated '.count($hydrated)." operation candidates.\n";
// Upgrade the retained pre-index shape, including unreadable newer work, without changing history.
\Paymenter\Extensions\Servers\Domains\Services\RenewalRetrySchema::install();
\Illuminate\Support\Facades\Schema::table('domains_operations',function($table):void{$table->dropIndex('domains_operations_management_progress_i');$table->dropColumn('management_pending');});
$raw=$held->getRawOriginal('policy');
DB::table('domains_operations')->where('id',$held->id)->update(['policy'=>'unreadable retained hold']);
try {
    $retained=$held->fresh()->getRawOriginal();
    \Paymenter\Extensions\Servers\Domains\Services\ManagementProgressIndex::install();
    same($retained,array_diff_key($held->fresh()->getRawOriginal(),['management_pending'=>true]),'Backfill preserves request evidence and dates');
    $hydrated=[];$measuring=true;$rows=$controller->status($request,$d->id)->getData(true);$measuring=false;
    same($queuedLock->id,$rows['lock']['id'],'Backfill keeps the older queued setting');
    same($held->id,$rows['nameservers']['id'],'Unreadable newer work stays visible after backfill');
    expect($rows['nameservers']['unreadable']??false,'Backfill does not silently discard unreadable work');
    expect(count($hydrated)<=21,'Backfilled history keeps bounded poll hydration');
} finally {
    DB::table('domains_operations')->where('id',$held->id)->update(['policy'=>$raw,'management_pending'=>null]);
    \Paymenter\Extensions\Servers\Domains\Services\ManagementProgressIndex::install();
}
same($recoverable->id,$controller->status($request,$d->id)->getData(true)['nameservers']['id'],'Backfill retains an older recoverable hold behind newer exhausted holds');
// The current DNS fact and its recovery helper are independent of an older completed change.
[$dnsDomain,$dnsDriver]=$holding(placeholderComFixtureName('pending-dns-fact-placeholder.com'));
$dnsDomain->server->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$state->srv=[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>'sip.example.com.']];
$inventory=static fn()=>['dns'=>['state'=>'complete','records'=>$state->dns],'srv'=>['state'=>'complete','records'=>$state->srv],
    'ds'=>['state'=>'complete','records'=>[]],'glue'=>['state'=>'partial','records'=>[]]];
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::save($dnsDomain->fresh(),$inventory(),$clock->now());
$dnsOp=$submit($dnsDomain,$dnsDriver,'dns',['records'=>[['name'=>'@','type'=>'TXT','address'=>'saved record','mx_pref'=>10]],
    'preimage_digest'=>\Paymenter\Extensions\Servers\Domains\Services\ManagementPayload::digest([])]);
same('succeeded',$management->run($dnsOp->id,$dnsDriver)->state,'DNS change completes before the later failed sync');
$clock->instant=$clock->now()->modify('+1 minute');Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::failPrimary($dnsDomain->fresh(),$clock->now());
$dnsPage=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$dnsDomain->id]);
$factRow=static fn($html,$label)=>storefrontDom($html)->query('//*[contains(concat(" ",normalize-space(@class)," ")," fi-in-entry ")][.//*[@role="term"][normalize-space(.)="'.$label.'"]]')->item(0);
$missing=$factRow($dnsPage->html(),'DNS records');
expect($missing&&str_contains($missing->textContent,'Not read yet')&&str_contains($missing->textContent,'Use Sync now to read them.'),'Unread DNS keeps the current recovery instruction after a saved change');
expect(str_contains($missing->textContent,'Saved.'),'The saved request remains a separate progress line under unread DNS');
same('Saved.',ManagementProgress::for($dnsDomain->fresh())['dns']['text'],'Failed sync does not rewrite the completed request');
$dnsPage->assertSee('DNS records')->assertSee('Saved.');
// Advance the engine and UI clocks before recording the recovered observation.
$readAt=$clock->now()->modify('+1 minute');$clock->instant=$readAt;Carbon\Carbon::setTestNow($readAt);Carbon\CarbonImmutable::setTestNow($readAt);
\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::save($dnsDomain->fresh(),$inventory(),$readAt);
$dnsPage->call('pollManagement');$available=$factRow($dnsPage->html(),'DNS records');
expect($available&&str_contains($available->textContent,'1 record')&&str_contains($available->textContent,'1 SRV')
    &&str_contains($available->textContent,'As of')&&str_contains($dnsPage->html(),$readAt->format(DATE_ATOM)),'Available DNS keeps its as-of time and SRV count beside management status');
$dnsPage->assertSee('DNS records')->assertSee('Saved.');
echo "Management pending controls, duplicate requests, local status and immediate start passed.\n";$nativeSuccessful=true;
