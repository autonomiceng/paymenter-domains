<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/renewal-runtime.php';
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Labels, RequestFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, FeedObserved, OfferState, Query, TermSupport};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Models\{Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow, DomainPage};
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;

// A second Enom renewal inside 24 hours says it will wait, and until when, before the invoice is made.
[$first, $d, $b] = $makeRenewal(placeholderComFixtureName('renew-repeat-wait-placeholder.com'));
[$engine, $writer, $completion] = $compose($b);
same('pending', $engine->run($first->id, $writer)->state, 'First annual renewal sends');
$state->expiry = '2028-10-18'; $state->charge = '8.00';
same('succeeded', $completion->run($first->id)->state, 'First annual renewal is confirmed');
$completedAt = new DateTimeImmutable($first->fresh()->completed_at->format(DATE_ATOM));
$goes = $completedAt->modify('+24 hours')->setTimezone(new DateTimeZone('UTC'));
$clock->instant = $completedAt->modify('+6 hours');
activeCommerceFacts($clock->now(), $clock->now()->modify('+30 days'), $ports);
$observeRenewalCost($b);
$t = Tld::where('server_id', $d->server_id)->where('tld', 'com')->sole();
$facts = TermSupport::attach('com', OfferState::facts($t->catalogue_facts), TermSupport::REVIEWED_EVIDENCE['enom']['com'] ?? null);
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts' => OfferState::encodeFacts($facts)]);
$ports->apply(new FeedObserved($b, 'com', [new Cost('renew', 2, true, '16.0000', CostUnit::TermTotal,
    'enom_renew_annual_total', $clock->now(), $clock->now()->modify('+30 days'))]), $ports->readOffers(new Query($b, ['com'], 'renew'), Context::Staff)[0]->revision);
require __DIR__.'/Support/owner-pages.php';
$writes = [$state->writes, count($state->calls), Operation::count()];
$present = $owners->present($d->fresh());
$preInvoiceAt=$present->renewNow['repeat_at']??null;
same($goes->getTimestamp(), isset($present->renewNow['repeat_at']) ? (new DateTimeImmutable($present->renewNow['repeat_at']))->getTimestamp() : null,
    'Renew now knows when a new renewal could go: the last completion + 24 hours');
app('view')->share('keys', array_fill_keys(['setup', 'recovery', 'code', 'renew', 'auto-renew', ...\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS], str_repeat('a', 64)));
app('view')->share('errors', new Illuminate\Support\ViewErrorBag);
$html = view('domains::show', ['domain' => $present, 'summary' => null])->render();
$dialog = storefrontDom($html)->query('//dialog[@id="edit-renew"]//*[@data-renewal-repeat]')->item(0);
$words = $dialog ? trim(preg_replace('/\s+/', ' ', $dialog->textContent)) : '';
expect(str_starts_with($words, 'The registrar accepts one renewal of a domain every 24 hours. If you renew now, the renewal goes to the registrar automatically at'),
    'The Renew dialog says why it would wait before Create invoice: '.$words);
same('form', $dialog?->parentNode?->nodeName, 'The note sits in the dialog form, beside its rows');
expect(preg_match('/\.domains-dialog-sheet form > \.domains-hint \{[^}]*padding:0 1\.25rem/', file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/styles.blade.php')) === 1,
    'The note has the same side padding as the dialog rows');
expect($dialog !== null && str_contains($dialog->ownerDocument->saveHTML($dialog), 'datetime="'.$goes->format('Y-m-d\TH:i:s').'+00:00"'), 'The dialog gives the fixed time in the viewer\'s zone');
same($writes, [$state->writes, count($state->calls), Operation::count()], 'Showing the wait sends and creates nothing');
// A day later the dialog says nothing about a wait.
$clock->instant = $completedAt->modify('+25 hours');
expect(!isset($owners->present($d->fresh())->renewNow['repeat_at']), 'No wait once 24 hours have passed');

$clock->instant=$completedAt->modify('+6 hours');
// The preview and paid execution use the same completion beyond two pages of newer invalid candidates.
$failures=[];
$check=static function(bool $ok,string $message)use(&$failures):void{if(!$ok){$failures[]=$message;}};
$decoys=[];
for($i=0;$i<205;$i++){
    $copy=$first->fresh()->replicate();$copy->fill(['operation_key'=>'repeat-decoy-'.$i,'invoice_id'=>null,'invoice_item_id'=>null,
        'completed_at'=>$completedAt->modify('+1 minute')]);$copy->save();$decoys[]=$copy->id;
}
DB::flushQueryLog();DB::connection()->enableQueryLog();
try {
    $preview=Paymenter\Extensions\Servers\Domains\Services\RenewalRepeatWait::forDomain($d->fresh(),$clock->now())?->format(DATE_ATOM);
    $queries=DB::getQueryLog();
} finally { DB::connection()->disableQueryLog();DB::flushQueryLog(); }
$operationReads=array_values(array_filter($queries,fn($query)=>str_contains($query['query'],'from "domains_operations"')));
$accountReads=array_values(array_filter($queries,fn($query)=>str_contains($query['query'],'from "domains_accounts"')));
echo 'Repeat preview reads: '.count($operationReads).' operation, '.count($accountReads)." account.\n";
$check(count($operationReads)>0&&count($operationReads)<=3,'Recent completions are read in bulk across the full history');
foreach($operationReads as $query){
    $check(preg_match('/limit ([0-9]+)/',$query['query'],$limit)===1&&(int)$limit[1]<=100,'Each repeat preview operation read is bounded to at most 100 rows');
}
$check(count($accountReads)===1,'The repeat preview reads its account once, not for each completion');
$locked=DB::transaction(static fn()=>Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d->fresh()));
$executionWait=Paymenter\Extensions\Servers\Domains\Services\RenewalRepeatWait::next($locked['domain'],$locked['operations'],$clock->now(),Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($locked['account']));
$check($preview!==null&&$executionWait!==null&&(new DateTimeImmutable($preview))->getTimestamp()===$executionWait->getTimestamp()&&$executionWait->getTimestamp()===$goes->getTimestamp(),'Invalid recent rows beyond two pages do not hide the authoritative locked completion');
Operation::whereIn('id',$decoys)->delete();
// A lookup failure keeps settled invoice state and emits only a safe exception class.
$failLookup=true;$lookupAttempts=0;$diagnostics=[];
DB::listen(static function($query)use(&$failLookup,&$lookupAttempts):void{
    if(str_contains($query->sql,'"completed_at" is not null')){
        $lookupAttempts++;if($failLookup){throw new RuntimeException('synthetic-private-renewal-data');}
    }
});
Illuminate\Support\Facades\Event::listen(Illuminate\Log\Events\MessageLogged::class,static function($event)use(&$diagnostics):void{
    if($event->message==='Domains renewal wait unavailable.'){$diagnostics[]=$event->context;}
});
$retained=['status'=>'paid'];
$projection=new Paymenter\Extensions\Servers\Domains\Services\DomainState(['active',null],[],null,[],'On',false,retained:$retained);
$renewNow=new ReflectionMethod(Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::class,'renewNow');
$check($retained===$renewNow->invoke($owners,$d->fresh(),true,$projection),'Failed repeat lookup keeps the retained state instead of offering an unchecked renewal');
expect($lookupAttempts>0,'The fallback fixture reaches the repeat lookup');
$check($diagnostics===[['exception_class'=>RuntimeException::class]],'Failure logs no exception message, domain, or query');
$failLookup=false;
same($goes->getTimestamp(),(new DateTimeImmutable($renewNow->invoke($owners,$d->fresh(),true,$projection)['repeat_at']))->getTimestamp(),'Successful recovery restores the repeat notice');
$offsetNow=$completedAt->modify('+23 hours')->setTimezone(new DateTimeZone('Pacific/Kiritimati'));
same($goes->getTimestamp(),Paymenter\Extensions\Servers\Domains\Services\RenewalRepeatWait::forDomain($d->fresh(),$offsetNow)?->getTimestamp(),'The recent-completion SQL cutoff preserves the instant across time zones');
$other=clone $d;$other->registrar_key='namesilo';
$otherOptions=$renewNow->invoke($owners,$other,true,$projection);
expect(($otherOptions['terms']??[])!==[]&&!isset($otherOptions['repeat_at']),'Non-Enom renewal options remain available without a repeat time');
// Compare the actual pre-invoice time with a second paid renewal's fixed execution evidence.
$invoice=$billing->invoice($d->id,$user,2);$second=Operation::where('invoice_id',$invoice->id)->sole();
App\Helpers\ExtensionHelper::addPayment($invoice,null,$second->amount);Cache::flush();
same('queued',$engine->run($second->id,$writer)->state,'The second paid renewal waits');
$second=$second->fresh();
same('renewal_repeat_wait',$second->safe_code,'The paid renewal has the repeat reason');
$check($preInvoiceAt!==null&&(new DateTimeImmutable($preInvoiceAt))->getTimestamp()===(new DateTimeImmutable($second->policy['renewal_execution']['eligible_at']))->getTimestamp(),'Pre-invoice repeat_at agrees exactly with the paid execution time');
expect($failures===[],implode('; ',$failures));

$nativeSuccessful = true; echo "Renew dialog explains the repeat wait passed.\n";
