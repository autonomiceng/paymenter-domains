<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff monitoring over synthetic local evidence.
declare(strict_types=1);
require __DIR__.'/Support/staff-monitoring.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB,Route};
use Filament\Notifications\Livewire\Notifications;
use Livewire\Livewire;
use Filament\Facades\Filament;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{WatchResource,RoutingProfileResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\{ListWatches,ViewWatch};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource\Pages\{ListConfiguration as ListTemplates,ViewConfiguration as ViewTemplate};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFacts,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsCollector,DnsContext,DnsQuery,DnsReading};
use Paymenter\Extensions\Servers\Domains\Models\{WatchAudit,WatchName,WatchProfile};
use Paymenter\Extensions\Servers\Domains\Services\StaffWatchWrites as Writes;
extract($checked());
// A queued check keeps feedback on the current page rather than losing the toast to a redirect.
config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900]);
// Distinct DOM identity disconnects the prior Livewire timer when switching cadence.
$pollKey=static function($page):string{
    $doc=new DOMDocument;@$doc->loadHTML($page->html());
    $nodes=(new DOMXPath($doc))->query('//*[@*[starts-with(name(),"wire:poll")]]');
    same(1,$nodes->length,'Exactly one polling element');$key=$nodes->item(0)->getAttribute('wire:key');
    expect($key!=='','Polling element has a stable key');return $key;
};
$secondTab=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false);
$passiveKey=$pollKey($secondTab);
$check=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->callAction('read')->assertSee('wire:poll.5s="pollCheck"',false)
    ->assertNotified(\Filament\Notifications\Notification::make()->success()->title('Check requested')
        ->body('This takes up to about six minutes. This page updates by itself.')->persistent())
    ->assertNoRedirect();
$pendingKey=$pollKey($check);expect($pendingKey!==$passiveKey,'Changing polling mode replaces the timer element');
same('Monitoring uses 0 registrar reads per domain per day.',$check->instance()->getAction('read')->getTooltip(),'Check cost is visible before requesting it');
same(1,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->count(),'Check request recorded once');
$originalWait=$check->get('pendingCheck');
$requestAt=\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->first()->created_at->getTimestamp();
same($requestAt+360,$originalWait[0],'Deadline starts at the stored accepted request time');
\Carbon\Carbon::setTestNow(now()->addSeconds(120));
$waitingPages=['second tab'=>$secondTab,'reload'=>Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])];
foreach($waitingPages as $label=>$waitingPage){
    if($label==='reload'){$waitingPage->assertSee('wire:poll.5s="pollCheck"',false);same($originalWait,$waitingPage->get('pendingCheck'),'Reload restores the original wait');}
    $waitingPage->callAction('read')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotified('Check already requested')->assertNoRedirect();
    same($originalWait,$waitingPage->get('pendingCheck'),$label.' joins the same wait without extending it');
}
$check->callAction('read')->assertNotified('Check already requested');
same($originalWait,$check->get('pendingCheck'),'Retry preserves the original deadline');
same(1,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::where('subject_id',$id)->where('event','staff_read_requested')->count(),'Throttled tabs do not add a request');
\Carbon\Carbon::setTestNow();
$check->call('pollCheck')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotNotified('Check finished');
same(WatchFacts::date($at+900),panelRowValue($check->html(),'Last check'),'Pending page keeps its saved Last check');
// A read already in flight can start before the request and save a newer Last check afterwards.
$completedAt=$requestAt-1;DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','delegation')->update(['due_at'=>$completedAt,'backoff_until'=>null]);
$t=$dns->begin($id,$uid,'delegation',$completedAt);expect($t!==null,'In-flight DNS ticket');
expect($dns->finish($t,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('delegation','external'),$requestAt+1),'In-flight DNS result saved after the request');
$check->call('pollCheck')->assertSee(WatchFacts::date($completedAt))->assertNotified('Check finished')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNoRedirect();
same($passiveKey,$pollKey($check),'Completion restores the stable passive element');
same(WatchFacts::date($completedAt),panelRowValue($check->html(),'Last check'),'Poll updates the Last check entry itself');
$check->call('pollCheck')->assertNotNotified('Check finished');
foreach($waitingPages as $label=>$waitingPage){
    $waitingPage->call('pollCheck')->assertNotified('Check finished')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false);
    same(WatchFacts::date($completedAt),panelRowValue($waitingPage->html(),'Last check'),$label.' receives the new Last check');
}
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotNotified('Check finished');
// Autonomous work after Check finished must refresh this same open page, including Next check.
$autonomousAt=$requestAt+120;
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$autonomousAt,'backoff_until'=>null]);
$t=$dns->begin($id,$uid,'points',$autonomousAt);expect($t!==null,'Autonomous points ticket');
$check->call('refreshFacts')->assertNotNotified('Check finished');same(\Paymenter\Extensions\Servers\Domains\Presentation\State::Checking->label(),panelRowValue($check->html(),'Next check'),'Passive refresh shows the running check');
expect($dns->finish($t,new \Paymenter\Extensions\Servers\Domains\Dns\DnsReading('points',website:true,email:true),$autonomousAt+1),'Autonomous points result');
$audits=\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count();
$check->call('refreshFacts')->assertSee(WatchFacts::date($autonomousAt))->assertNotNotified('Check finished');expect(panelRowValue($check->html(),'Next check')!==\Paymenter\Extensions\Servers\Domains\Presentation\State::Checking->label(),'The running check ends on the open page');
same(WatchFacts::date($autonomousAt),panelRowValue($check->html(),'Last check'),'Passive refresh updates Last check on the open page');
same(WatchFacts::for(WatchName::find($id))['Checks']['Next check']['value'],panelRowValue($check->html(),'Next check'),'Passive refresh replaces Next check after work finishes');
same($audits,\Paymenter\Extensions\Servers\Domains\Models\WatchAudit::count(),'Passive refresh requests no checks or writes');
// Age the synthetic request claim so a second real Check now action can be tested.
DB::table('domains_watch_staff_claims')->where('subject_type','watch')->where('subject_id',$id)->where('action','read')->update(['created_at'=>time()-301]);
// If no saved check moves, support advice appears only after the six-minute wait.
$waitingAt=\Carbon\Carbon::createFromTimestampUTC($autonomousAt+2);\Carbon\Carbon::setTestNow($waitingAt);
$check=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction('read');
same($pendingKey,$pollKey($check),'Another request reuses the pending key, not the passive node');
\Carbon\Carbon::setTestNow($waitingAt->copy()->addSeconds(359));
$check->call('pollCheck')->assertSee('wire:poll.5s="pollCheck"',false)->assertNotNotified('Check is taking longer');
$reload=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSee('wire:poll.5s="pollCheck"',false);
same($check->get('pendingCheck'),$reload->get('pendingCheck'),'Reload at 359 seconds keeps the original deadline');
\Carbon\Carbon::setTestNow($waitingAt->copy()->addSeconds(360));
$check->call('pollCheck')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified(\Filament\Notifications\Notification::make()->warning()->title('Check is taking longer')
    ->body('Last check has not changed. Checks keep retrying. Open Registrars and check the background worker.')->persistent())->assertNoRedirect();
same($passiveKey,$pollKey($check),'Timeout also disconnects the pending timer');
$check->call('pollCheck')->assertNotNotified('Check is taking longer');
$reload->call('pollCheck')->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified('Check is taking longer');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertDontSee('wire:poll.5s',false)->assertSee('wire:poll.60s="refreshFacts"',false)->assertNotified('Check is taking longer');
\Carbon\Carbon::setTestNow();

// A failure notice retries the registry. DNS work cannot acknowledge the request, even after a reload.
$expiryWork = DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','expiry');
$failRegistry = function(int $when) use ($id,$uid,$f,$expiryWork): void {
    $expiryWork->update(['due_at'=>$when,'backoff_until'=>null]);
    $ticket = $f['registry']->begin($id,$uid,$when);
    expect($ticket && $f['registry']->finish($ticket,['result'=>'unknown','reason'=>'endpoint_failed','safe_code'=>'expiry_unknown'],
        str_repeat('c',64),$when+1), 'Failed registry observation saved');
};
$failRegistry(time()-30);
$clearCooldown = fn()=>DB::table('domains_watch_staff_claims')->where('subject_id',$id)->where('action','read')->update(['created_at'=>time()-301]);
$notificationStatuses = static function (): array {
    $inbox = new Notifications;
    $inbox->mount();
    return $inbox->notifications->map(fn ($notification) => $notification->getStatus())->values()->all();
};
$future = time()+86400;
$dnsWork = DB::table('domains_watch_work')->where('watch_id',$id)->whereIn('kind',['delegation','points']);
$dnsWork->update(['due_at'=>$future]);
$retry = Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]);
$read = \Filament\Actions\Testing\TestAction::make('read')->schemaComponent('sheet','content');
$retry->callAction($read);
same([], $retry->get('pendingCheck'), 'A throttled registry retry cannot join a different request');
$clearCooldown();
$retry->callAction($read);
same($future,(int)$dnsWork->min('due_at'),'Registry backoff leaves healthy DNS checks alone');
$expiryWork->update(['due_at'=>$future,'backoff_until'=>time()-1]);
$retry->callAction($read);
expect($expiryWork->value('due_at')<$future && $retry->get('pendingCheck'), 'The retry advances the failed registry kind after backoff');
noticeDns($f,time()+2);
$retry=Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->call('pollCheck');
expect((bool)$retry->get('pendingCheck'), 'A newer unrelated DNS result leaves the registry request pending');
$failRegistry(time()+4);
$retry->call('pollCheck');
same(['warning'], $notificationStatuses(), 'A failed registry retry warns staff');
same([], $retry->get('pendingCheck'), 'A failed retry finishes with a warning');
$clearCooldown();
$expiryWork->update(['backoff_until'=>time()-1]);
$retry->callAction($read);
noticeRegistry($f,time()+6);
$notificationStatuses(); // Drain the request notice before checking the result.
$retry->call('pollCheck');
same(['success'], $notificationStatuses(), 'A completed registry retry succeeds');
same([], $retry->get('pendingCheck'), 'A new registry result completes its retry');

// An extra CNAME hop on one of two mapped hosts puts the apex AAAA just beyond the ten-query cap on cursor 1.
DB::transaction(function () use ($service,$source): void {
    $sibling = $service->replicate();
    $sibling->save();
    $sibling->properties()->create(['key'=>'domain','name'=>'Domain','value'=>'shop.example.com']);
    $source->replicate()->fill(['service_id'=>$sibling->id,'hostname'=>'shop.example.com'])->save();
});
$reader = new class implements DnsReader {
    public ?string $ipv4 = null;

    public function read(DnsQuery $query, DnsContext $context): DnsAnswer
    {
        if (!$context->reserve()) { return DnsAnswer::unknown($query, 'budget_exhausted'); }
        [$host, $type] = [$query->name, $query->type];
        $record = ['host'=>$host,'class'=>'IN','type'=>$type,'ttl'=>60];
        return match ($query->key()) {
            'www.example.com|CNAME' => DnsAnswer::rrset($query, [[...$record,'target'=>'edge.example.net']]),
            'example.com|A' => $this->ipv4 === null ? DnsAnswer::negative($query) : DnsAnswer::rrset($query, [[...$record,'ip'=>$this->ipv4]]),
            'example.com|AAAA' => DnsAnswer::rrset($query, [[...$record,'ipv6'=>'2001:db8::8']]),
            default => DnsAnswer::negative($query),
        };
    }
};
$collector = new DnsCollector($reader);
$pointsWork = DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points');
$addressAt = time()-120;
$pointsWork->update(['cursor'=>0,'due_at'=>$addressAt,'backoff_until'=>null]);
$ticket = $dns->begin($id,$uid,'points',$addressAt);
expect($ticket !== null, 'Initial points ticket');
[$reading,$cursor,$sourceCursor] = $collector->collect($ticket, new DnsContext(static fn () => 0.0));
expect($dns->finish($ticket,$reading,$addressAt+1,$cursor,$sourceCursor), 'First collection saves the IPv6 answer');
$failedAt = $addressAt+60;
$pointsWork->update(['due_at'=>$failedAt,'backoff_until'=>null]);
$ticket = $dns->begin($id,$uid,'points',$failedAt);
expect($ticket && $dns->finish($ticket,new DnsReading('points',queries:[
    DnsAnswer::unknown(new DnsQuery('example.com','A'), 'resolver_failed'),
]),$failedAt+1,$ticket->cursor,$ticket->sourceCursor), 'Points read fails and leaves the next rotation due');
$pointsWork->update(['backoff_until'=>time()-1]);
$clearCooldown();
$retry = Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->callAction($read);
$pending = $retry->get('pendingCheck');
same('points', $pending[2] ?? null, 'Check now targets the failed DNS work');
$requests = WatchAudit::where('subject_type','watch')->where('subject_id',$id)->where('event','staff_read_requested');
$requestCount = $requests->count();
$retry->callAction($read);
same($pending, $retry->get('pendingCheck'), 'A duplicate DNS retry keeps its original wait');
same($requestCount, $requests->count(), 'A duplicate DNS retry adds no request');
$notificationStatuses();
$retry->call('pollCheck');
same($pending, $retry->get('pendingCheck'), 'The saved failure alone cannot acknowledge a new retry');
same([], $notificationStatuses(), 'Polling without a newer result sends no feedback');

$rotationAt = time()+2;
$ticket = $dns->begin($id,$uid,'points',$rotationAt);
expect($ticket && $ticket->cursor === 1, 'The retry claims the next collector rotation');
$context = new DnsContext(static fn () => 0.0);
[$reading,$cursor,$sourceCursor] = $collector->collect($ticket,$context);
$apex = array_column(array_filter($reading->queries,fn ($q) => $q['name'] === 'example.com'),'state','type');
same([DnsContext::MAX_QUERIES,'partial','rotation','negative',null],
    [$context->used(),$reading->result(),$reading->safeCode(),$apex['A'] ?? null,$apex['AAAA'] ?? null],
    'The full query budget leaves only a negative A answer for the apex addresses');
$attempts = (int) $pointsWork->value('attempts');
expect($dns->finish($ticket,$reading,$rotationAt+1,$cursor,$sourceCursor), 'The bounded points observation is saved');
same([$attempts,null], [(int) $pointsWork->value('attempts'),$pointsWork->value('backoff_until')], 'Rotation incurs no new failure or backoff');
$retry->call('pollCheck');
$facts = WatchFacts::for(WatchName::findOrFail($id));
$public = $facts['Evidence']['Public DNS'];
$times = storefrontDom('<div id="rotation-time">'.$public['sub'].'</div>')->query('//*[@id="rotation-time"]//time');
$ipv6 = panelRowValue($retry->html(),'Public DNS') ?? '';
expect(preg_match('/^AAAA\b.*2001:db8::8$/s',$ipv6) === 1, 'A rotation retains the earlier IPv6 answer');
same([$addressAt,['info']], [strtotime($times->item(0)?->getAttribute('datetime') ?? ''),$notificationStatuses()],
    'A rotation retains the earlier observation time and reports partial progress');
same([], $retry->get('pendingCheck'), 'The matching rotation completes the requested attempt');
$retry->call('pollCheck');
same([], $notificationStatuses(), 'A completed rotation sends feedback only once');
$reload = Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id]);
same([], $reload->get('pendingCheck'), 'Reload does not reopen a completed rotation');
same([], $notificationStatuses(), 'Reload does not resend rotation feedback');

// Exercise the same cap with two saved addresses and a newer positive A answer.
$collectPoints = static function (int $at, int $startCursor) use ($pointsWork,$dns,$id,$uid,$collector): array {
    $pointsWork->update(['cursor'=>$startCursor,'due_at'=>$at,'backoff_until'=>null]);
    $ticket = $dns->begin($id,$uid,'points',$at);
    expect($ticket !== null, 'Dual-stack points ticket');
    $context = new DnsContext(static fn () => 0.0);
    [$reading,$cursor,$sourceCursor] = $collector->collect($ticket,$context);
    expect($dns->finish($ticket,$reading,$at+1,$cursor,$sourceCursor), 'Dual-stack observation saved');
    $apex = array_column(array_filter($reading->queries,fn ($q) => $q['name'] === 'example.com'),'state','type');
    return [$context->used(),$reading->result(),$reading->safeCode(),$apex['A'] ?? null,$apex['AAAA'] ?? null];
};
$dualAt = $rotationAt + 60;
$reader->ipv4 = '192.0.2.8';
same(['rrset','rrset'],array_slice($collectPoints($dualAt,0),3),'Initial collection saves both apex families');
$reload->call('refreshFacts')->assertSee('192.0.2.8')->assertSee('2001:db8::8');
$newAAt = $dualAt + 60;
$reader->ipv4 = '192.0.2.18';
same([DnsContext::MAX_QUERIES,'partial','rotation','rrset',null],$collectPoints($newAAt,1),
    'The capped rotation saves a positive apex A answer and skips AAAA');
$reload->call('refreshFacts')->assertDontSee('192.0.2.8');
$page = storefrontDom($reload->html());
foreach (['2001:db8::8'=>$dualAt, '192.0.2.18'=>$newAAt] as $address=>$observedAt) {
    $row = $page->query('//div[@data-domains-row][starts-with(normalize-space(*[1]),"Public DNS") and contains(., "'.$address.'")]')->item(0);
    expect($row !== null, 'Public DNS still shows '.$address.' after the capped refresh');
    $observed = array_map(fn ($time) => strtotime($time->getAttribute('datetime')),iterator_to_array($page->query('.//time',$row)));
    same([$observedAt],$observed,'Public DNS shows the exact observation time for '.$address);
}

// Follow the same saved addresses through an alias cutover and a subsequent resolver failure.
$savePoints = static function (int $at, DnsReading $reading) use ($pointsWork,$dns,$id,$uid): void {
    $pointsWork->update(['due_at'=>$at,'backoff_until'=>null]);
    $ticket = $dns->begin($id,$uid,'points',$at);
    expect($ticket && $dns->finish($ticket,$reading,$at+1,$ticket->cursor,$ticket->sourceCursor), 'Points evidence saved');
};
$cname = static fn (string $owner, string $target): DnsAnswer => DnsAnswer::rrset(new DnsQuery($owner,'CNAME'), [
    ['host'=>$owner,'class'=>'IN','type'=>'CNAME','ttl'=>60,'target'=>$target],
]);
$address = static fn (string $owner, string $ip): DnsAnswer => DnsAnswer::rrset(new DnsQuery($owner,'A'), [
    ['host'=>$owner,'class'=>'IN','type'=>'A','ttl'=>60,'ip'=>$ip],
]);
$failedA = DnsAnswer::unknown(new DnsQuery('example.com','A'), 'resolver_failed');
$savePoints($newAAt+60, new DnsReading('points', queries:[
    $failedA, $cname('www.example.com','edge.example.net'), $address('edge.example.net','192.0.2.30'),
]));
$reload->call('refreshFacts')->assertSee('192.0.2.18')->assertSee('2001:db8::8')->assertDontSee('192.0.2.30');

$aliasAt = $newAAt+120;
$savePoints($aliasAt, new DnsReading('points', website:true, email:false, queries:[
    $cname('example.com','edge.example.net'),
]));
$alias = '/^CNAME\b.*\bedge\.example\.net$/s';
$reload->call('refreshFacts')->assertDontSee('192.0.2.18')->assertDontSee('2001:db8::8');
expect(preg_match($alias,panelRowValue($reload->html(),'Public DNS') ?? '') === 1, 'The owner alias replaces its previous direct addresses');

$savePoints($aliasAt+60, new DnsReading('points', queries:[$failedA]));
$reload->call('refreshFacts')->assertDontSee('192.0.2.18')->assertDontSee('2001:db8::8');
expect(preg_match($alias,panelRowValue($reload->html(),'Public DNS') ?? '') === 1, 'An inconclusive read retains the owner alias');
$public = WatchFacts::for(WatchName::findOrFail($id))['Evidence']['Public DNS'];
$when = storefrontDom('<div>'.$public['sub'].'</div>')->query('//time')->item(0);
same($aliasAt,strtotime($when?->getAttribute('datetime') ?? ''),'The saved alias keeps its own observation time');

$savePoints($aliasAt+120, new DnsReading('points', website:true, partial:true, queries:[
    $address('example.com','192.0.2.38'),
], budgetLimited:true));
$reload->call('refreshFacts')->assertDontSee('2001:db8::8')->assertDontSee('edge.example.net');
expect(preg_match('/^A\b.*\b192\.0\.2\.38$/s',panelRowValue($reload->html(),'Public DNS') ?? '') === 1,
    'A later direct A cannot inherit IPv6 across the alias cutover');

// Staff have no Stop: Pause is the supported way to halt checks, and the page then reads Paused.
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertActionDoesNotExist('retire')->callAction('pause')->assertNotified('Monitoring paused');same('paused',WatchName::find($id)->state,'Pause saves');
same('Paused',WatchFindings::status(WatchName::find($id),[])['label'],'Paused status');
Livewire::actingAs($manager)->test(ViewWatch::class,['record'=>$id])->assertSee('Paused')->assertActionHidden('read')->assertActionHidden('pause')->assertActionVisible('resume')->assertDontSee('wire:poll.5s',false);


$nativeSuccessful=true;echo "Staff monitoring polling passed\n";
