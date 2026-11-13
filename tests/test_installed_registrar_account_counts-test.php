<?php
// The registrar account page: each count links to a list showing exactly those rows, and amber means staff act; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ListOperations;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts,RegistrarFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
// One staff price read checks staff access and the catalogue gate a fixed few times; per term it would add 36 here.
const GRANT_READS=12;
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$server=(int)$tlds[0]->server_id;
$staff=staffUser([Staff::VIEW,Staff::POLICY]);Auth::setUser($staff);
$account=Account::where('server_id',$server)->sole();
$bind=['server_id'=>$server,'registrar_key'=>$account->registrar_key,'account_fingerprint'=>$account->account_fingerprint];
// One domain per request, so every request is bound to its own domain as the Registrar requests list requires.
$request=static function(string $name,string $action,string $state,array $extra=[])use($bind):Operation {
    static $n=0;$n++;
    $d=Domain::create([...$bind,'domain'=>$name.'.com','sld'=>$name,'tld'=>'com','user_id'=>900+$n,'service_id'=>900+$n,'status'=>'registration_pending']);
    return Operation::create([...$bind,'domain_id'=>$d->id,'service_id'=>$d->service_id,'authority_generation'=>$d->authority_generation,
        'operation_key'=>'counts-'.$n,'action'=>$action,'currency'=>'USD','amount'=>'10.00','state'=>$state,'payload'=>[],'attempt'=>[],'policy'=>[],'cost'=>[],'funding'=>[],...$extra]);
};
$unconfirmed=$request('counts-paid-unconfirmed','register','uncertain',['funding_basis'=>'paid_invoice']);
$request('counts-held-renewal','renew','held',['safe_code'=>'renewal_definitely_unsent']);
$request('counts-unconfirmed-change','update_nameservers','uncertain');
$heldOrder=$request('counts-held-order','register','held');
$request('counts-unpaid','renew','awaiting_payment');
$pendingDone=$request('counts-done','transfer','succeeded');
// A request whose domain moved to another service is not listed, so it is not counted either.
$moved=$request('counts-moved','renew','queued');Domain::whereKey($moved->domain_id)->update(['service_id'=>999]);

// Staff access and the catalogue gate are read once per staff price read, not once per TLD and term (12 terms here).
$guard=new Paymenter\Extensions\Servers\Domains\Catalogue\Query(Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account),[(string)$tlds[0]->tld],'register');
DB::flushQueryLog();DB::enableQueryLog();
Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts::routed()->staffBatch([$guard]);
$grants=count(array_filter(DB::getQueryLog(),fn(array $q)=>preg_match('/from [`"](users|roles)[`"]|from [`"]settings[`"] where [`"]settingable_type[`"] is null/',$q['query'])===1));
DB::disableQueryLog();
echo "Staff and gate reads on one staff price read: $grants\n";expect($grants<=GRANT_READS,'Staff and gate reads stay bounded on one staff price read: '.$grants);
$html=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server])->assertSuccessful()->html();
// The page row for a label: its value, sub-line links and action links.
$row=static function(string $label,string $html):array {
    // A space before each closing tag keeps a value and the line under it apart in textContent.
    $doc=Dom\HTMLDocument::createFromString(str_replace('</',' </',$html),LIBXML_NOERROR);
    foreach($doc->querySelectorAll('[data-domains-row]') as $node){
        if(!str_contains($node->textContent,$label)){continue;}
        $links=[];foreach($node->querySelectorAll('a[href]') as $a){$links[trim(preg_replace('/\s+/',' ',$a->textContent))]=['href'=>$a->getAttribute('href'),'class'=>(string)$a->getAttribute('class')];}
        return ['text'=>trim(preg_replace('/\s+/',' ',$node->textContent)),'links'=>$links];
    }
    expect(false,'Row on the page: '.$label);return [];
};
// The rows a page link opens: the real list with the link's own filters.
$listed=static function(string $href,string $list)use($staff):int {
    parse_str((string)parse_url(html_entity_decode($href),PHP_URL_QUERY),$query);
    return Livewire::actingAs($staff)->withQueryParams($query)->test($list)->assertSuccessful()->instance()->getTableRecords()->total();
};
$requests=$row('Registrar requests',$html);
expect(preg_match('/\b5 open\b/',$requests['text'])===1,'Open requests count the listed open rows: '.$requests['text']);
same(5,$listed($requests['links']['View requests']['href']??'',ListOperations::class),'View requests opens exactly the open requests');
// Ticking only On hold on the list shows only the held order, not every other request.
same(1,$listed(RegistrarFacts::requestsUrl($server,['on_hold']),ListOperations::class),'On hold alone lists only held requests');

// Due in 30 days: a renewal that waits on our price read is told plainly, never amber and never a Sync prices job.
$unpriced=['unreadable'=>0,'without_cost'=>1,'unpriced'=>1];
$gap=RegistrarFacts::dueGap($account,$unpriced);
expect($gap!==null&&!$gap['act']&&str_starts_with($gap['text'],'1 renewal not priced yet. Fills in by itself after the '),'An unpriced renewal waits on the price read: '.json_encode($gap));
$line=(string)RegistrarFacts::dueLine($gap);
expect(!str_contains($line,'domains-warn')&&!str_contains($line,'Sync prices'),'Waiting on our price read is not amber: '.$line);
$undated=RegistrarFacts::dueGap($account,['unreadable'=>0,'without_cost'=>2,'unpriced'=>1]);
expect(str_contains($undated['text'],'1 renewal with no known date')&&str_contains($undated['text'],'1 renewal not priced yet.'),
    'Mixed gaps explain both the undated and unpriced renewals');
$mixedNote=(new ReflectionMethod(RegistrarSetup::class,'note'))->invoke(null,null,['value'=>'USD 9.00','low'=>false,'cause'=>null,'unreadable'=>0,'without_cost'=>2,'unpriced'=>1],$account);
expect(str_contains($mixedNote['text'],'1 renewal not priced yet.'),'The Registrars note retains the mixed unpriced count');
expect($undated['act']&&str_contains((string)RegistrarFacts::dueLine($undated),'domains-warn'),'A renewal with no known date needs staff, so it is amber');
// The Registrars list Note says the same words and is not amber for it.
$note=(new ReflectionMethod(RegistrarSetup::class,'note'))->invoke(null,null,['value'=>'USD 9.00','low'=>false,'cause'=>null,...$unpriced],$account);
expect(!$note['act']&&str_contains((string)$note['text'],'not priced yet')&&!str_contains((string)$note['text'],'Sync prices'),'The Note waits on our price read too: '.json_encode($note));

// A failed price read that we retry automatically (registrar timeout) stays grey and shows the retry time;
// only a cause staff must fix is amber, with its step.
$retryAt=now()->getTimestamp()+600;
$failRead=static function(string $code)use($server,$account,$retryAt):void {
    DB::table('domains_observation_queues')->updateOrInsert(['server_id'=>$server],['account_fingerprint'=>$account->account_fingerprint,'offer_lease'=>0,
        'offer_feed_failure'=>$code,'offer_feed_failed_at'=>now()->getTimestamp(),'offer_feed_succeeded_at'=>null,
        'offer_work'=>json_encode(['feed_due'=>$retryAt,'tries'=>1,'upgrade_generation'=>\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::generation()])]);
};
$failRead('read_deadline');
$progress=\Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::routed()?->progress($server)??[];
same(['retrying','read_deadline',$retryAt],[$progress['state']??null,$progress['failure']??null,$progress['due_at']??null],'The fixture read is retrying a timeout');
$retry=RegistrarFacts::dueGap($account,$unpriced);
same(["1 renewal not priced yet. The price read failed: Registrar didn't answer in time. Fills in by itself after we retry the read",false,$retryAt],
    [$retry['text']??null,$retry['act']??null,$retry['at']??null],'A timeout we retry is not a staff job and shows the retry time');
$retryLine=(string)RegistrarFacts::dueLine($retry);
expect(!str_contains($retryLine,'domains-warn')&&!str_contains($retryLine,'Fix that'),'A retried read is not amber: '.$retryLine);
$note=(new ReflectionMethod(RegistrarSetup::class,'note'))->invoke(null,null,['value'=>'USD 9.00','low'=>false,'cause'=>null,...$unpriced],$account);
expect(!$note['act'],'The Note does not make a retried read a job: '.json_encode($note));
$failRead('configuration_unverified');
$fix=RegistrarFacts::dueGap($account,$unpriced);
same(["1 renewal not priced yet. The price read failed: Couldn't verify the registrar account settings. Use More, then Edit API credentials. We then fill the cost on the next read.",true],
    [$fix['text']??null,$fix['act']??null],'A cause staff must fix is amber with its step');
expect(str_contains((string)RegistrarFacts::dueLine($fix),'domains-warn'),'A staff-fix read failure is amber');
same([],array_diff(array_keys(\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::SYNC_STEPS),array_keys(\Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::CAUSES)),'Every staff step names a known cause');
DB::table('domains_observation_queues')->where('server_id',$server)->update(['offer_feed_failure'=>null,'offer_work'=>null]);

// Domain alerts: the count, the Open alerts filter and the rows use one rule.
$alerted=static function(string $name,string $status,string $state,string $action='update_nameservers')use($bind):Domain {
    static $m=0;$m++;
    $d=Domain::create([...$bind,'domain'=>$name.'.com','sld'=>$name,'tld'=>'com','user_id'=>950+$m,'service_id'=>950+$m,'status'=>$status]);
    $op=Operation::create([...$bind,'domain_id'=>$d->id,'service_id'=>$d->service_id,'authority_generation'=>$d->authority_generation,
        'operation_key'=>'alerts-'.$m,'action'=>$action,'currency'=>'USD','amount'=>'0.00','state'=>$state,'payload'=>[],'attempt'=>[],'policy'=>[],'cost'=>[],'funding'=>[]]);
    ObligationAlerts::record($d,'operation_review',now()->subMinute()->getTimestamp(),(int)$op->id);
    return $d;
};
$live=$alerted('alerts-live','active','queued');
$doneAlert=$alerted('alerts-done','active','succeeded');      // Its review is outdated, but its missing expiry still needs staff.
$alerted('alerts-cancelled','cancelled','queued'); // The row reads Cancelled and shows no alert.
$held=$alerted('alerts-held','registration_pending','held','register'); // The row's step is the hold; it still waits on staff.
Cache::flush();
// The account page's System checks row: its count and its View domains link.
$alerts=Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::alerts($account);
// include visible staff tasks without a saved alert, including missing expiry.
$expected=[$unconfirmed->domain_id,$heldOrder->domain_id,$pendingDone->domain_id,$live->id,$doneAlert->id,$held->id];
same('6',$alerts['value'],'The count includes all six domains whose visible note needs staff');
same('Domains on this registrar account with open alerts. The sidebar counts all registrar accounts.',$alerts['help'],'The account row says it counts this account only');
parse_str((string)parse_url((string)$alerts['buttons'][0]->getUrl(),PHP_URL_QUERY),$query);
$records=Livewire::actingAs($staff)->withQueryParams($query)->test(ListDomains::class)->assertSuccessful()->instance()->getTableRecords();
same($expected,collect($records->items())->pluck('id')->sort()->values()->all(),'View domains opens exactly the domains needing staff');
expect(DomainFacts::status($live)['alert']!==null&&DomainFacts::status($held)['act'],'Each listed row asks staff to act');
same('6',(string)DomainResource::getNavigationBadge(),'The menu badge counts the same rows');
// Native encrypted casts must contain one unreadable request without breaking every candidate row.
foreach(['queued','held'] as $state){
    $bad=$request('unreadable-'.$state.'-placeholder','transfer',$state,['safe_code'=>$state==='held'?'processing':null]);
    Domain::whereKey($bad->domain_id)->update(['status'=>'transfer_pending']);
    DB::table('domains_operations')->where('id',$bad->id)->update(['payload'=>'synthetic-unreadable-ciphertext']);
    $domain=$bad->domain()->firstOrFail();
    $status=DomainFacts::status($domain);
    same(DomainFacts::UNREADABLE.' Check request #'.$bad->id.'.',$status['step'],'Unreadable request has a named recovery step');
    expect($status['act'],'Unreadable request requires staff');
    ObligationAlerts::record($domain,'operation_review',now()->getTimestamp(),(int)$bad->id);
    same(1,Paymenter\Extensions\Servers\Domains\Admin\Support\DomainQueries::status(Domain::whereKey($domain->id),[$status['key']])->count(),
        'Status filtering survives the unreadable request');
    expect(Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::alerts($account)['value']!==null,
        'Account alert counting survives the unreadable request');
}
// A retained active service is read-only forecast input; no acquisition/creation admission is under test.
$serviceId=DB::table('services')->insertGetId(['user_id'=>$staff->id,'currency_code'=>'USD','price'=>'10.00','status'=>'active']);
Domain::create([...$bind,'domain'=>'forecast-placeholder.com','sld'=>'forecast-placeholder','tld'=>'com',
    'user_id'=>$staff->id,'service_id'=>$serviceId,'status'=>'active','billing_managed'=>true,'provider_expires_at'=>$now->modify('+5 days'),'renewal_policy'=>[]]);
$renewPrice=DB::table('domains_prices')->where('server_id',$server)->where('tld','com')->where('action','renew')->where('term',1);
$observation=costEvidence($renewPrice->first());
foreach(['unobserved','stale','disabled'] as $case){
    $cost=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($observation);
    if($case==='stale'){$cost['observed_at']=$now->modify('-10 days')->format(DATE_ATOM);$cost['fresh_until']=$now->modify('-8 days')->format(DATE_ATOM);}
    if($case==='disabled'){$cost['enabled']=false;$cost['total']=null;}
    $renewPrice->update([...costColumns($case==='unobserved'?null:json_encode($cost))]);
    $forecast=\Paymenter\Extensions\Servers\Domains\Services\BalanceForecast::for($account,$now,$caps);
    same(1,$forecast['unpriced'],'Each observation case leaves one dated renewal unpriced');
    $missing=$case==='unobserved'?['com'=>1]:[];
    same($missing,$forecast['unobserved']??[],'Forecast distinguishes '.$case.' annual renewal observations');
    $gap=RegistrarFacts::readGap('1 renewal',['state'=>'ready','succeeded_at'=>$now->getTimestamp(),'remaining'=>0],$now->getTimestamp(),$missing);
    same($case==='unobserved',$gap['act'],'Only an unobserved cost after a successful read needs the registrar');
    if($case==='unobserved'){expect(str_contains($gap['text'],'Ask the registrar for a 1-year renewal cost for .com.'),'The gap uses the existing specific registrar request');}
}
$renewPrice->update([...costColumns($observation)]);
$gate=App\Models\Setting::where('key',\Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate::KEY)->sole();
$savedGate=$gate->value;$gate->value=json_encode(['state'=>'paused','generation'=>2]);$gate->save();
$inactive=RegistrarFacts::dueGap($account,$unpriced);
expect($inactive['act']&&str_contains($inactive['text'],\Paymenter\Extensions\Servers\Domains\Services\OfferUpgrade::MESSAGE),
    'Inactive catalogue needs the upgrade step rather than promising an automatically queued read');
$gate->value=$savedGate;$gate->save();
echo "Registrar account counts and links agree.\n";$nativeSuccessful=true;
