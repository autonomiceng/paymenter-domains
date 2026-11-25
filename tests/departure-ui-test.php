<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Rendered state, next-step and action admission behavior.
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
$argv[1]=renderOutput(null);
require __DIR__.'/Support/departure-render.php';
$nativeSuccessful=false;
$page->assertActionEnabled('stop');
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,State};
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Contracts\DnsReader;
use Paymenter\Extensions\Servers\Domains\Dns\{DnsAnswer,DnsContext,DnsQuery};
use Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords;
use Paymenter\Extensions\Servers\Domains\Services\{DepartureChecks,HoldingObservationWork,PublicRegistryReader};
$failures=[];$check=static function(bool $ok,string $why)use(&$failures):void{if(!$ok){$failures[]=$why;}};
$record=$list->instance()->getTableRecords()->firstWhere('id',$d->id);$table=$list->instance()->getTable();
$note=$table->getColumn('note')->record($record);$rendered=(string)$note->formatState($note->getState());
$dom=Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$rendered,LIBXML_NOERROR);
$word=$dom->querySelector('[data-domains-tone]');$reason=$dom->querySelector('[data-piece="sub"]');
$check($word!==null&&trim($word->textContent)===State::Delayed->label(),'The delayed attention item exposes its state');
$cause=DomainFacts::alertCause('departure_review',$record);
$check($reason!==null&&str_contains($reason->textContent,explode('. ',$cause)[1])&&str_contains($reason->textContent,Fact::day($record->provider_expires_at)->text),'The delayed item retains its next step and expiry data');
$check($table->getRecordUrl($record)===\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$record->id]),'The departure next step opens its domain');
$check($table->getColumn('auto_renew')->record($record)->getState()===State::Paused->label(),'The list shows paused renewal authority');
$dom=Dom\HTMLDocument::createFromString($customer,LIBXML_NOERROR);
$controls=$dom->querySelectorAll('button[aria-disabled="true"][aria-describedby^="departure-"]');
$check($controls->length>=7,'Paused renewal and registrar controls remain present');
foreach($controls as $button){
    $check(!$button->hasAttribute('disabled')&&$button->getAttribute('tabindex')!=='-1','Paused controls remain keyboard focusable');
    $check($button->getAttribute('type')==='button','Paused controls cannot submit a form');
    $check($dom->getElementById($button->getAttribute('aria-describedby'))!==null,'Paused controls name their reason');
}
// DNS stays editable while suspected, so the customer keeps the record values they would copy to a new host.
$check($dom->querySelector('table.domains-dns-inventory')!==null,'The suspected customer page keeps its DNS records table');
$check($dom->getElementById('glue')!==null,'The suspected customer page keeps its Glue records row');
// The staff head says where and when the absence was read; Registration dates the absence read.
$staffDom=Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$page->html(),LIBXML_NOERROR);
$fresh=$d->fresh();$read=\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at(strtotime($fresh->observation['renewal_ownership']['at']));
$detail=(string)$staffDom->querySelector('[data-piece="head-detail"]')?->textContent;
foreach([\Paymenter\Extensions\Servers\Domains\Management\Changes\ManagementNameservers::registrar($fresh),(string)$fresh->server->name,$read] as $fact){$check($fact!==''&&str_contains($detail,$fact),'The staff head names the registrar, account and absence read time: '.$fact);}
$owned=null;foreach($staffDom->querySelectorAll('[data-piece="row"]') as $row){if(trim((string)$row->querySelector('.domains-panel-row-k')?->textContent)==='In registrar account'){$owned=$row->textContent;}}
$check($owned!==null&&str_contains($owned,$read),'In registrar account carries the absence read time');
$absenceAt=strtotime($fresh->observation['renewal_ownership']['at']);
$railReads=$staffDom->querySelectorAll('[data-piece="trace"] [wire\:key="moment-'.$absenceAt.'"]');
$check($railReads->length===1,'Activity lists the absence read at its recorded time');
$absenceRow=$railReads->item(0)?->closest('[data-domains-tone]');
$check($absenceRow?->getAttribute('data-domains-tone')===State::UnderReview->tone(\Paymenter\Extensions\Servers\Domains\Presentation\Reader::Staff)->value,'The absence read has the staff review state');
// Nameservers and DNS records say where DNS is served while the domain is under review.
$case=\Paymenter\Extensions\Servers\Domains\Services\DepartureChecks::staffDnsCase($fresh);$rows=[];
foreach($staffDom->querySelectorAll('[data-piece="row"]') as $row){$rows[trim((string)$row->querySelector('.domains-panel-row-k')?->textContent)]=$row->textContent;}
$check($case!==null,'The suspected fixture has read nameservers, so its DNS case is known');
$check($case!==null&&str_contains($rows['Nameservers']??'',$case[0])&&array_filter(\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::storedNameservers($fresh)??[''],fn($ns)=>!str_contains($rows['Nameservers']??'',$ns))===[],'Nameservers names the DNS case and keeps the read nameservers');
$check($case!==null&&str_contains($rows['DNS records']??'',$case[1]),'DNS records names the DNS case');
$staffPage=static function()use($actor,$d):Dom\HTMLDocument {
    $page=Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful();
    return Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$page->html(),LIBXML_NOERROR);
};
$dates=static function(?Dom\Element $row):array {
    $dates=[];
    foreach($row?->querySelectorAll('time[datetime]')??[] as $time){$dates[]=strtotime($time->getAttribute('datetime'));}
    return $dates;
};
$readDates=static function(Dom\HTMLDocument $dom)use($dates):array {
    $recheck=$dom->querySelector("button[wire\\:click^=\"mountAction('check_departure',\"]")?->closest('[data-piece="row"]');
    return [$dates($dom->querySelector('[id$="-row-first-read"]')),$dates($recheck)];
};
$dns=new class implements DnsReader {
    public function read(DnsQuery $query,DnsContext $context):DnsAnswer
    {
        $context->reserve();
        return $query->type==='A'?DnsAnswer::rrset($query,[['host'=>$query->name,'type'=>'A','class'=>'IN','ttl'=>60,'ip'=>'8.8.8.8']]):DnsAnswer::negative($query);
    }
};
$registryDelay=0;
app()->instance(DepartureChecks::class,new DepartureChecks($dns,$clock,static function(DnsReader $dns)use($d,$clock,&$registryDelay):PublicRegistryReader {
    return new PublicRegistryReader($dns,['services'=>[[['com'],['https://rdap.example.com']]]],static function()use($d,$clock,&$registryDelay):array {
        $clock->instant=$clock->now()->modify('+'.$registryDelay.' seconds');
        return ['status'=>200,'body'=>json_encode([
            'objectClassName'=>'domain','ldhName'=>$d->domain,'status'=>['ok'],
            'events'=>[['eventAction'=>'expiration','eventDate'=>'2028-10-25']],
            'entities'=>[['roles'=>['registrar'],'vcardArray'=>['vcard',[['fn',[],'text','Example Registrar']]]]],
        ],JSON_THROW_ON_ERROR)];
    });
}));
$originalFacts=$d->fresh()->observation;
$legacy=$originalFacts;
unset($legacy['departure_check'],$legacy['departure_reads'],$legacy['renewal_ownership']);
$d->refresh()->update(['observation'=>$legacy]);
$job=static fn()=>new SyncDomainRecords((int)$actor->id,(int)$d->id,$d->authority_generation,$d->account_fingerprint,true);
$job()->failed(null);
$check($readDates($staffPage())===[[$absenceAt],[$clock->now()->getTimestamp()]],'A failed check without a schedule retains the native absence date');

// An older holding has only the native absence record. The worker fails preflight but saves registry facts in finally.
$server=DB::table('extensions')->where('id',$d->server_id);
foreach([0,1] as $registryDelay){
    $stale=$d->fresh()->observation;
    unset($stale['departure_check']);
    $d->refresh()->update(['observation'=>$stale]);
    $clock->instant=$clock->now()->modify('+61 seconds');
    $failedAt=$clock->now()->getTimestamp();
    $before=count($observation->calls);
    $server->update(['enabled'=>false]);
    try{$job()->handle($observer);}finally{$server->update(['enabled'=>true]);}
    $saved=$d->fresh()->observation;
    $scheduledAt=$failedAt+$registryDelay;
    same($before,count($observation->calls),'The failed preflight does not reach the registrar');
    same($failedAt,SyncDomainRecords::latestFailedReadAt($d->fresh()),'The worker saves the failed attempt time');
    same([$scheduledAt,'facts'],[$saved['departure_check']['at']??null,$saved['departure_check']['registry']['result']??null],'The worker creates the marker when the registry answers');
    same($legacy['record_failure'],$saved['record_failure'],'The worker retains the original registrar answer');
    $check($readDates($staffPage())===[[$absenceAt],[$failedAt,$scheduledAt+86400]],
        'The failed attempt and automatic due time remain visible when the registry marker follows by '.$registryDelay.' seconds');
}

// Use the original registrar history and the worker's real marker. A later definite answer is retained through an automatic failure.
$saved['departure_reads']=$originalFacts['departure_reads'];
$d->refresh()->update(['observation'=>$saved]);
$clock->instant=$clock->now()->modify('+61 seconds');
$answerAt=$clock->now()->getTimestamp();
same('domain_not_in_account',$observer->run($d->id,readRecords:true),'The registrar confirms a recheck');
$clock->instant=$clock->now()->setTimestamp($scheduledAt+86400);
$automaticAt=$clock->now()->getTimestamp();
$observation->fault='transport';
DB::table('domains_observation_queues')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint]);
$queue=DB::table('domains_observation_queues')->sole();
(new HoldingObservationWork($observer,$clock))->turn($queue,static fn()=>null);
$afterAutomatic=$d->fresh()->observation;
same(true,$afterAutomatic['departure_check']['rechecked']??false,'The automatic read completes');
same($automaticAt,strtotime($afterAutomatic['record_failure']['at']),'The automatic read saves its failure time');
same($answerAt,strtotime($afterAutomatic['renewal_ownership']['at']),'The automatic failure retains the last definite registrar read');
$after=$staffPage();
$check($after->querySelector('[id$="-row-departure-next-step"]')!==null,'After the automatic attempt staff keep the next step');
$check($readDates($after)===[[$absenceAt],[$automaticAt,$answerAt]],'An automatic failure dates the failed attempt and retained recheck separately');
$clock->instant=$clock->now()->modify('+61 seconds');
$job()->failed(null);
$check($readDates($staffPage())===[[$absenceAt],[$clock->now()->getTimestamp(),$answerAt]],
    'A later manual failure keeps the retained recheck date');
// Phase B, registrar DNS: when the registry lists another registrar nothing serves the records, so Edit and Edit SRV are
// muted by the row's own reason. While it lists ours the row has no such reason: Edit stays paused only by the
// review's section notice, like every registrar change under review.
$dnsButtons=static function()use($d,$departer,$actor):array{
    Illuminate\Support\Facades\Auth::setUser($departer);
    $html=Livewire\Livewire::actingAs($departer)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->html();
    Illuminate\Support\Facades\Auth::setUser($actor);
    $dom=Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$html,LIBXML_NOERROR);$out=[];
    foreach($dom->querySelectorAll('[data-piece="row"]') as $row){if(trim((string)$row->querySelector('.domains-panel-row-k')?->textContent)!=='DNS records'){continue;}
        // Muted, and separately whether it is described by this row's own reason (not a section notice).
        foreach($row->querySelectorAll('button,a') as $b){$why=$dom->getElementById((string)$b->getAttribute('aria-describedby'));$out[trim($b->textContent)]=['disabled'=>$b->getAttribute('aria-disabled')==='true','reason'=>$why!==null&&$row->contains($why)];}}
    return $out;
};
$check(\Paymenter\Extensions\Servers\Domains\Services\DepartureChecks::dnsCase($d->fresh())==='registrar','The suspected fixture uses our registrar DNS');
$other=$dnsButtons();
$check(isset($other['Edit'])&&$other['Edit']['disabled']&&$other['Edit']['reason'],'Another registrar at the registry mutes DNS Edit with its reason');
$check(!isset($other['Edit SRV'])||$other['Edit SRV']['disabled']&&$other['Edit SRV']['reason'],'Another registrar at the registry mutes Edit SRV with its reason');
Illuminate\Support\Facades\DB::transaction(function()use($d):void{$fresh=$d->fresh();$p=$fresh->observation;$p['departure_check']['registry']['registrar']='eNom, LLC';$fresh->update(['observation'=>$p]);});
$ours=$dnsButtons();
$check(isset($ours['Edit'])&&!$ours['Edit']['reason'],'DNS Edit is not muted as stopped while the registry lists our registrar');
$check(isset($ours['Edit'])&&$ours['Edit']['disabled'],'DNS Edit stays paused by the review while the registry lists our registrar');
// A blank registrar name names no other registrar, so DNS stays editable.
Illuminate\Support\Facades\DB::transaction(function()use($d):void{$fresh=$d->fresh();$p=$fresh->observation;$p['departure_check']['registry']['registrar']=" \t ";$fresh->update(['observation'=>$p]);});
$blank=$dnsButtons();
$check(isset($blank['Edit'])&&!$blank['Edit']['reason'],'DNS Edit is not muted as stopped when the registry names no registrar');
// Absence saved before departure checks existed has no marker; the staff page still opens and offers Check again.
Illuminate\Support\Facades\DB::transaction(function()use($d):void{$fresh=$d->fresh();$p=$fresh->observation;unset($p['departure_check']);$fresh->update(['observation'=>$p]);});
try{Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->assertSuccessful()->assertActionVisible('check_departure');$legacy=true;}
catch(Throwable $error){$legacy=false;echo 'Legacy absence page: '.$error->getMessage()."\n";}
$check($legacy,'A suspected holding without a departure marker renders with Check again');
// Sync now stays in the head under review and queues the same departure read as Check again.
$review=Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id]);
$review->assertActionVisible('sync_now')->assertActionEnabled('sync_now');
Illuminate\Support\Facades\Schema::create('cache',function($t){$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
Illuminate\Support\Facades\Schema::create('cache_locks',function($t){$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
Illuminate\Support\Facades\Queue::fake();config(['queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.retry_after'=>900,'cache.default'=>'database']);
$review->callAction('sync_now');
$check(Illuminate\Support\Facades\Queue::pushed(\Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords::class,fn($job)=>$job->departure)->count()===1,'Sync now under review queues one departure read');
$section=view('domains::components.customer.notice',['variant'=>'section','state'=>State::UnderReview,'slot'=>new Illuminate\Support\HtmlString('The saved check is pending.')])->render();
$check(str_contains(strip_tags($section),State::UnderReview->label()),'A section notice exposes its state word');
expect($failures===[],implode("\n",$failures));
$nativeSuccessful=true;echo "Departure attention state, next step, paused renewals and focusable non-submitting controls passed.\n";
