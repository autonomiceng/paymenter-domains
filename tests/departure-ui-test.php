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
foreach([\Paymenter\Extensions\Servers\Domains\Services\ManagementNameservers::registrar($fresh),(string)$fresh->server->name,$read] as $fact){$check($fact!==''&&str_contains($detail,$fact),'The staff head names the registrar, account and absence read time: '.$fact);}
$owned=null;foreach($staffDom->querySelectorAll('[data-piece="row"]') as $row){if(trim((string)$row->querySelector('.domains-panel-row-k')?->textContent)==='In registrar account'){$owned=$row->textContent;}}
$check($owned!==null&&str_contains($owned,$read),'In registrar account carries the absence read time');
// Nameservers and DNS records say where DNS is served while the domain is under review.
$case=\Paymenter\Extensions\Servers\Domains\Services\DepartureChecks::staffDnsCase($fresh);$rows=[];
foreach($staffDom->querySelectorAll('[data-piece="row"]') as $row){$rows[trim((string)$row->querySelector('.domains-panel-row-k')?->textContent)]=$row->textContent;}
$check($case!==null,'The suspected fixture has read nameservers, so its DNS case is known');
$check($case!==null&&str_contains($rows['Nameservers']??'',$case[0])&&array_filter(\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::storedNameservers($fresh)??[''],fn($ns)=>!str_contains($rows['Nameservers']??'',$ns))===[],'Nameservers names the DNS case and keeps the read nameservers');
$check($case!==null&&str_contains($rows['DNS records']??'',$case[1]),'DNS records names the DNS case');
// The staff next step survives the automatic recheck when the domain is still absent.
Illuminate\Support\Facades\DB::transaction(function()use($d,$clock):void {
    $fresh=$d->fresh();$p=$fresh->observation;$at=$clock->now()->getTimestamp()-172800;
    $p['departure_check']=['identity'=>\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($fresh),'at'=>$at,
        'registry'=>['registrar'=>'Example Registrar','status'=>['ok'],'result'=>'facts','at'=>$at],'rechecked'=>true];
    $fresh->update(['observation'=>$p]);
});
$after=Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->html(),LIBXML_NOERROR);
$step=null;foreach($after->querySelectorAll('[data-piece="row"]') as $row){if(trim((string)$row->querySelector('.domains-panel-row-k')?->textContent)==='Departure check'){$step=$row;}}
$check($step!==null,'After the automatic recheck staff keep the Departure check step');
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
