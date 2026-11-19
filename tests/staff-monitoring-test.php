<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff monitoring over synthetic local evidence.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsQuery,DnsReading};
use Paymenter\Extensions\Servers\Domains\Models\{WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Presentation\{MonitoringEvidence,Plain,State};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
// Unchecked: Monitoring, with Last check Missing and its cause.
$list=Livewire::actingAs($manager)->test(ListWatches::class)->assertCanSeeTableRecords([$watch]);$clean($list->html(),'monitoring list');
assertDomainNameOneLine($list->html(),$watch->canonical_name,'Monitoring list');
same(null,$list->instance()->getSubheading(),'No list subheading');
foreach(['canonical_name'=>'Domain','status'=>'Status','registration'=>'Registrar','delegation.dns'=>'Nameservers','last_check'=>'Last check','expiry'=>'Expires'] as $name=>$label){same($label,$column($list,$name)->getLabel(),'Column '.$name);}
foreach(['findings','user_id','state','work_due'] as $gone){same(null,$column($list,$gone),'Old column '.$gone.' removed');}
same(State::Monitored->label(),$column($list,'status')->record($watch)->getState(),'Unchecked domain is Monitored');
same(State::NotReadYet->label(),$column($list,'last_check')->record($watch)->getState(),'Last check not read yet');
same(State::NotReadYet->label(),$column($list,'registration')->record($watch)->getState(),'Registrar not read yet');
same('Customer #'.$uid,$column($list,'customer')->record($watch)->getState(),'Customer has its own column');same(null,$column($list,'canonical_name')->record($watch)->getDescriptionBelow(),'The name is one line');
foreach(['status'=>'Status','customer'=>'Customer','expiry'=>'Expires within'] as $name=>$label){same($label,$filter($list,$name)->getLabel(),'Filter '.$name);}
same(null,$filter($list,'attention'),'Attention filter merged into Status');same(null,$filter($list,'drift'),'Drift filter merged into Status');same(null,$filter($list,'state'),'State filter merged into Status');
// operator-pass-review-R1 The status filter includes the shared Needs attention destination.
same(['attention'=>'Needs attention','monitoring'=>State::Monitored->label(),'problem'=>State::NeedsReview->label(),'failed'=>State::Failed->label(),'paused'=>State::Paused->label(),'stopped'=>State::Stopped->label()],$filter($list,'status')->getOptions(),'Status filter options');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','paused')->assertCountTableRecords(0)->assertSee('No monitored domains match')->assertSee('Clear the search or filters to see all rows.')->assertDontSee('Turn on monitoring on a hosting product.');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('customer',['customer'=>(string)$uid])->assertCanSeeTableRecords([$watch]);

// Checked: nameservers moved away and expiry within 30 days is Problem found with the reason.
extract($checked());
$list=Livewire::actingAs($manager)->test(ListWatches::class);$record=WatchName::find($id);
same(State::NeedsReview->label(),$column($list,'status')->record($record)->getState(),'Problem found reads Needs review');
same('Nameservers changed. Expires in '.$days.' days.',$column($list,'note')->record($record)->getState(),'Note retains the observed mismatch and the expiry warning');
same('Other',$column($list,'delegation.dns')->record($record)->getState(),'Nameservers elsewhere read Other');
expect($column($list,'last_check')->record($record)->getState()!==State::NotReadYet->label(),'Last check has a time');
expect(str_contains((string)$column($list,'last_check')->record($record)->formatState($column($list,'last_check')->getState()),'data-domains-time'),'Last check shows in the viewer time');
same(null,$column($list,'expiry')->record($record)->getDescriptionBelow(),'Expires is one value; the days left are in Note');
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','problem')->assertCanSeeTableRecords([$record]);
Livewire::actingAs($manager)->test(ListWatches::class)->filterTable('status','monitoring')->assertCanNotSeeTableRecords([$record]);

// Monitored domain page: heading pill, header buttons, sections, one notice.
$view=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]);$page=$view->instance();$clean($view->html(),'monitored domain');
expect(str_contains(html_entity_decode(strip_tags($page->getHeading()->toHtml())),$watch->canonical_name) && str_contains($page->getSubheading()->toHtml(),\Paymenter\Extensions\Servers\Domains\Presentation\State::Monitored->label()),'An active watch keeps its own state in the head');
expect(str_contains(panelNoticeText($view->html()),'Nameservers changed'),'Observed mismatch leads the sheet');
$seen=preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~si','',$view->html())),ENT_QUOTES|ENT_HTML5));
same([1,0],[substr_count($seen,'Nameservers changed'),substr_count($seen,'Nameservers changed:')],'Nameservers changed shows once, with no notice repeating the found nameservers');
same([WatchResource::getUrl(),0],array_keys($page->getBreadcrumbs()),'Breadcrumb leads back to the monitored domains list');
foreach(['read','pause','note'] as $name){$view->assertActionVisible($name);}
$view->assertActionHidden('resume');
foreach(['read','pause'] as $name){same(false,$page->getAction($name)->isConfirmationRequired(),$name.' has no dialog');}
$view->assertActionDoesNotExist('retire');
$view->assertActionVisible(\Filament\Actions\Testing\TestAction::make('profile')->schemaComponent('sheet','content'));
$view->assertSee('Expires in '.$days.' days')->assertDontSee('Findings')->assertDontSee('Saved domain checks')->assertDontSee('Notification activity')->assertDontSee('Watch state');
$facts=WatchFacts::for($record);same(['Domain','Checks','Evidence'],array_keys($facts),'External monitoring keeps domain facts, checks and saved public evidence');expect(!isset($facts['Domain']['Registrar records'],$facts['Domain']['Billing and ownership']),'External monitoring makes no registrar or billing claim');same('Other',$facts['Domain']['Registrar']['value']??'Missing','Registrar from the saved registration');
// Last check already says when; Evidence from that same check says no second Checked time. An older answer keeps its own.
$lastAt=(int)($facts['Checks']['Last check']['at']??0);expect($lastAt>0&&isset($facts['Evidence']['Public DNS'])&&$record->points!==null,'Fixture saves public DNS and a last check');
$saved=fn(int $at)=>\Illuminate\Support\Facades\DB::table($record->points->getTable())->where('id',$record->points->id)->update(['started_at'=>$at,'completed_at'=>$at+1]);$saved($lastAt-3600);
expect(str_contains((string)WatchFacts::for(WatchName::find($id))['Evidence']['Public DNS']['sub'],'Checked'),'An older saved answer keeps its Checked time');
$saved($lastAt);
same(null,WatchFacts::for(WatchName::find($id))['Evidence']['Public DNS']['sub'],'Evidence does not repeat the Last check time');
// Separate checks seconds apart in one minute each keep their own time; only the DNS answer Last check describes drops it.
$times=fn($html)=>array_map(fn($n)=>$n->getAttribute('datetime'),iterator_to_array(storefrontDom('<div id="times">'.$html.'</div>')->query('//*[@id="times"]//time')));
$minute=(intdiv($lastAt,60)+1)*60;$registryId=(int)DB::table('domains_watch_registry')->where('watch_id',$id)->max('id');
$registry=fn(int $at)=>DB::table('domains_watch_registry')->where('id',$registryId)->update(['started_at'=>$at,'completed_at'=>$at+1]);
$registry($minute+40);$saved($minute+10);$facts=WatchFacts::for(WatchName::find($id));
same($minute+40,$facts['Checks']['Last check']['at'],'A newer registry check supplies Last check');
same([gmdate('c',$minute+10)],$times($facts['Evidence']['Public DNS']['sub']),'An earlier DNS answer in the same minute keeps its own Checked time');
$saved($minute+50);$facts=WatchFacts::for(WatchName::find($id));
same([$minute+50,null],[$facts['Checks']['Last check']['at'],$facts['Evidence']['Public DNS']['sub']],'The DNS answer that supplies Last check shows no second time');
$saved($minute-3600);same([gmdate('c',$minute-3600)],$times(WatchFacts::for(WatchName::find($id))['Evidence']['Public DNS']['sub']),'Older evidence keeps its Checked time');
// With no template to compare, saved answers fill DNS records with their own exact time, even beside a newer check in the same minute.
$registry($minute+40);$template=WatchName::find($id)->ns_profile_id;DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>null]);
$untemplated=WatchName::find($id);same(null,\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::watchProfile($untemplated),'No DNS template applies');
$untemplated->setRelation('points',new \Paymenter\Extensions\Servers\Domains\Models\WatchDnsObservation(['check_kind'=>'points','started_at'=>$minute+17,'completed_at'=>$minute+18,'result'=>'complete','partial'=>false,'valid_until'=>$minute+300,
    'facts'=>['queries'=>[['name'=>$untemplated->canonical_name,'type'=>'A','records'=>[['value'=>'192.0.2.10']]]]]]));
$records=WatchFacts::for($untemplated,['keys'=>[],'summary'=>null])['Checks']['DNS records'];
same('A @ → 192.0.2.10',$records['value'],'Saved answers fill DNS records without a template');
same([gmdate('c',$minute+17)],$times($records['sub']),'DNS records keeps the saved answer time');
DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>$template]);$saved($lastAt);

// A newer failed registry attempt retains the separately dated, in-scope observation.
$attemptAt = $minute + 120;
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$attemptAt,'backoff_until'=>null]);
$ticket = $f['registry']->begin($id,$uid,$attemptAt);
expect($ticket !== null && $f['registry']->finish($ticket, ['result'=>'unknown','reason'=>'endpoint_failed','safe_code'=>'expiry_unknown'],
    str_repeat('c',64), $attemptAt+1), 'A local failed read is recorded');
$facts = WatchFacts::for(WatchName::findOrFail($id));
same($attemptAt, $facts['Checks']['Last check']['at'], 'Last check uses the failed attempt time');
same('danger', $facts['Checks']['Last check']['tone'], 'The latest failed read is grouped as a failure');
same($expiry, \Carbon\CarbonImmutable::parse($facts['Domain']['Expires']['value'])->toDateString(), 'The earlier observed expiry survives the failed read');
expect(str_contains($facts['Domain']['Expires']['sub'],WatchFacts::date($minute+40)), 'The earlier expiry carries its own observation time');
DB::table('domains_watch_registry')->where('id',$registryId)->delete();
$facts = WatchFacts::for(WatchName::findOrFail($id));
same(State::CouldntRead, $facts['Domain']['Expires']['state'], 'A failed registry read without prior facts has no observed expiry');

// An authoritative absence supersedes an earlier expiry even after another failed lookup.
$readRegistry = function (int $at, array $answer) use ($f, $id, $uid): void {
    DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry')->update(['due_at'=>$at,'backoff_until'=>null]);
    $ticket = $f['registry']->begin($id,$uid,$at);
    expect($ticket && $f['registry']->finish($ticket,$answer,str_repeat('c',64),$at+1), 'Registry observation saved');
};
noticeRegistry($f,$attemptAt+60);
$absentAt = $attemptAt+120;
$readRegistry($absentAt,['result'=>'not_registered','reason'=>'not_registered','safe_code'=>'not_registered']);
$readRegistry($absentAt+60,['result'=>'unknown','reason'=>'endpoint_failed','safe_code'=>'expiry_unknown']);
$facts = WatchFacts::for(WatchName::findOrFail($id));
same('Not registered', $facts['Checks']['Registry']['value'], 'The latest definitive registration status survives a failure');
expect(str_contains($facts['Checks']['Registry']['sub'], WatchFacts::date($absentAt)), 'The absence retains its observation time');
same(null, $facts['Domain']['Expires']['value'] ?? null, 'A superseded expiry cannot return');

$pointsWork = DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points');
$addressAt = $absentAt+180;
$query = new DnsQuery($watch->canonical_name,'A');
$answer = DnsAnswer::rrset($query,[['host'=>$query->name,'class'=>'IN','type'=>'A','ttl'=>60,'ip'=>'192.0.2.39']]);
$address = new DnsReading('points',website:true,email:false,queries:[$answer]);
$savePoints = static function (int $when, DnsReading $reading) use ($pointsWork,$dns,$id,$uid): void {
    $pointsWork->update(['due_at'=>$when,'backoff_until'=>null]);
    $ticket = $dns->begin($id,$uid,'points',$when);
    expect($ticket && $dns->finish($ticket,$reading,$when+1), 'Points observation saved');
};
$savePoints($addressAt, $address);
$savePoints($addressAt+60, new DnsReading('points'));
$facts = WatchFacts::for(WatchName::findOrFail($id));
same('A @ → 192.0.2.39', $facts['Evidence']['Public DNS']['value'], 'A failed refresh keeps the last public address');
same([gmdate('c',$addressAt)], $times($facts['Evidence']['Public DNS']['sub']), 'The retained address keeps its own read time');
same($addressAt+60, $facts['Checks']['Last check']['at'], 'The failure has a separate attempt time');

// Name-wide absence needs only one reply. Type-specific absence needs both address families.
$negativeAnswers = [
    'name absent' => [DnsAnswer::negative($query, reason:'nxdomain')],
    'both families absent' => [DnsAnswer::negative($query), DnsAnswer::negative(new DnsQuery($query->name,'AAAA'))],
];
$when = $addressAt+120;
foreach ($negativeAnswers as $scope => $answers) {
    $savePoints($when, $address);
    $savePoints($when+60, new DnsReading('points',email:false,queries:$answers,budgetLimited:true));
    $evidence = MonitoringEvidence::forWatch(WatchName::findOrFail($id));
    same([[], 'negative', $when+60], [$evidence['public'], $evidence['public_read'], $evidence['public_at']],
        'A partial collection with '.$scope.' supersedes the saved address and its time');
    $when += 120;
}
$view->call('refreshFacts');
same(Plain::None->label(), panelRowValue($view->html(), 'Public DNS'), 'The panel renders the confirmed absence');

// A failed A reply keeps that family's address independently of a new AAAA answer.
$v6Query = new DnsQuery($query->name,'AAAA');
$v6 = DnsAnswer::rrset($v6Query,[['host'=>$query->name,'class'=>'IN','type'=>'AAAA','ttl'=>60,'ipv6'=>'2001:db8::39']]);
$dual = new DnsReading('points',website:true,email:false,queries:[$answer,$v6]);
$onlyA = new DnsReading('points',website:true,queries:[$answer]);
$failedA = new DnsReading('points',website:true,queries:[DnsAnswer::unknown($query,'resolver_failed'),$v6]);
$evidence = static fn (): array => MonitoringEvidence::forWatch(WatchName::findOrFail($id));
$savePoints($when, $dual);
$savePoints($when+60, $failedA);
$retained = $evidence();
same([['A @ → 192.0.2.39','AAAA @ → 2001:db8::39'],null,true],
    [$retained['public'],$retained['public_at'],$retained['public_stale']], 'Mixed observations are historical and have no shared date');
same(['A'=>$when,'AAAA'=>$when+60],array_map(fn ($family) => $family['at'],$retained['public_families']),
    'The failed family keeps its date while the answered family advances');
$savePoints($when+120, new DnsReading('points',email:false,queries:[DnsAnswer::negative($query)],budgetLimited:true));
$afterNegative = $evidence();
same([['AAAA @ → 2001:db8::39'],$when+60,[],$when+120],
    [$afterNegative['public'],$afterNegative['public_at'],$afterNegative['public_families']['A']['answers'],$afterNegative['public_families']['A']['at']],
    'An A negative replaces A alone and leaves the last IPv6 address and date');
$savePoints($when+180, $failedA);
same(['AAAA @ → 2001:db8::39'],$evidence()['public'],'A later failure cannot revive an address replaced by a negative reply');

// A source change and a DNS template change each fence history from the next partial check.
DB::table($source->getTable())->where('id',$source->id)->increment('revision');
$savePoints($when+240, $onlyA);
same(['A @ → 192.0.2.39'],$evidence()['public'],'The next attempt cannot borrow IPv6 from a different source target');
$savePoints($when+300, $dual);
$replacement = DB::transaction(function () use ($profile): WatchProfile {
    $replacement = $profile->replicate()->fill(['name'=>'Replacement template','enabled'=>true]);
    $replacement->save();
    return $replacement;
});
DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>$replacement->id,'profile_revision'=>$replacement->revision]);
$savePoints($when+360, $onlyA);
same(['A @ → 192.0.2.39'],$evidence()['public'],'The next attempt cannot borrow IPv6 from a different DNS template');

$nativeSuccessful=true;echo "Staff monitoring list and details passed\n";
