<?php
// Native and installed active-catalogue witness, synthetic wire only.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/price-sync.php';
require_once __DIR__.'/staff-tlds.php';
require_once __DIR__.'/native-commerce.php';
require_once __DIR__.'/installed-offer-evidence.php';
use App\Models\{Currency,Server};
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Facts,FactsObserved,OfferScheduler,OfferState,Query};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation,Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,NativeCatalogue,StaffSettingsReview as Review,StaffSettingsWrites,Storefront};
// Retired synchronous import/fill algorithm coverage lives in offer-feed-test.php and
// offer-work-test.php. Active review/withdrawal/restore coverage: offer-staff-policy-test.php,
// offer-restore-sales-test.php; physical cap/stale reviews: enom-test-send-limit-test.php.
// Frozen checkout/funding remains in offer-commerce-test.php and installed registration phases.
$clock=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock { public DateTimeImmutable $at;public function __construct(){$this->at=new DateTimeImmutable('2026-11-02T12:00:00Z');}public function now():DateTimeImmutable{return $this->at;} };
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,
    'domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true,
    'domains.enom_test_sends_per_day'=>500,'domains.enom_test_sends_per_minute'=>100000]);
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
$entry=new \Paymenter\Extensions\Servers\Domains\Domains;$entry->installed();$entry->boot();
tldPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic active TEST catalogue','type'=>'server','extension'=>'Domains','enabled'=>true]);
$settings=app(StaffSettingsWrites::class);$bindings=app(AccountBindings::class);
$review=static fn(string $action,?int $id=null)=>new Review($staff->id,$server->id,$id,$action,
    Review::ticket($staff->id,$server->id,$id,$action,Review::stamp($server->id,$id)),'Review synthetic active catalogue');
$write=static fn(string $action,array $data=[],?int $id=null)=>$settings->run($review($action,$id),$data);
$write('configure',['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid','token'=>'catalogue-token']);
$write('probe');$binding=AccountBindings::binding(Account::where('server_id',$server->id)->sole());
$write('test_renewal',['accept'=>true,'verified'=>true]);
$GLOBALS['catalogueBulk']=static fn(int $years)=>['com'=>['10.0000','10.0000',null],'net'=>['11.0000','11.0000',null]];
$before=count($GLOBALS['catalogueCalls']);$scheduler=OfferScheduler::routed();$scheduler->sync($binding);
same($before,count($GLOBALS['catalogueCalls']),'Requesting account sync only queues local work');
\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferWork::class)->accountTurn($server->id);
same(['PE_GetRetailPricing','PE_GetRetailPricing','PE_GetRetailPricing','PE_GetRetailPricing','GetTLDList'],array_column(array_slice($GLOBALS['catalogueCalls'],$before),'Command'),'Installed active feed uses bounded bulk reads');
$tld=Tld::where('server_id',$server->id)->where('tld','com')->sole();
same(null,$tld->catalogue_facts,'Bulk prices invent no provider support facts');
$scheduler->requestFacts($binding,'com');\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferWork::class)->accountTurn($server->id);
$facts=OfferState::facts($tld->fresh()->catalogue_facts);
same([1],$facts->terms['register'],'Cold registry reads prove only returned registration terms');
same([], $facts->terms['renew']??[],'Price and registry responses cannot invent annual renewal support');
same([],app(Storefront::class)->endings(),'Unknown renewal support keeps public sales closed');
// Independently declared fixture support, then real staff intent/publication. Never copy price data into facts.
$clock->at=$clock->now()->modify('+1 second');$at=$clock->now();$ports=CallerPorts::routed();
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1],'renew'=>[1],'transfer'=>[]],[],true,false,false,'independent_synthetic_support',$at,$at->modify('+1 day'))),
    $ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0]->revision);
$tld->refresh();
$availability=['enabled'=>true,'register'=>true,'transfer'=>false,'renew'=>true,'min_years'=>1,'max_years'=>1,'verified'=>true];
$write('tld_settings',$availability,$tld->id);$write('publish',[],$tld->id);
$write('seller',['seller'=>(string)$server->id,'register'=>true,'transfer'=>false],$tld->id);
same([],app(Storefront::class)->endings(),'Staff price/support review cannot enable paused spending');
$before=count($GLOBALS['catalogueCalls']);
Livewire::actingAs($staff)->test(ViewTld::class,['record'=>$tld->id])->assertSuccessful();
same($before,count($GLOBALS['catalogueCalls']),'Installed staff page never sends provider reads');
$stale=$review('publish',$tld->id);$write('tld_settings',[...$availability,'register'=>false],$tld->id);
try{$settings->run($stale,[]);expect(false,'Changed intent invalidates a review');}catch(DomainException $e){same('Settings changed. Reload this page.',$e->getMessage(),'Exact stale review refusal');}
same($before,count($GLOBALS['catalogueCalls']),'Stale review sends nothing');
$write('tld_settings',$availability,$tld->id);$write('publish',[],$tld->id);
$money=[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),Domain::count(),Operation::count()];
$rows=DB::table('domains_prices')->where('server_id',$server->id)->orderBy('id')->get()->toJson();
$GLOBALS['catalogueBulkFailed']=true;$scheduler->sync($binding);\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::resolve(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferWork::class)->accountTurn($server->id);$GLOBALS['catalogueBulkFailed']=false;
same($rows,DB::table('domains_prices')->where('server_id',$server->id)->orderBy('id')->get()->toJson(),'Failed buffered bulk read retains every prior price byte');
same($money,[App\Models\Order::count(),App\Models\Invoice::count(),App\Models\InvoiceTransaction::count(),Domain::count(),Operation::count()],'Catalogue work creates no customer obligations');
same(false,Account::where('server_id',$server->id)->sole()->processing_enabled,'Catalogue setup leaves processing paused');
expect(count(Review::historyRows($server->fresh()))>0,'Active staff changes retain encrypted audit');
echo "Installed active Enom bulk/facts queue, paused sales, staff review and retained price/financial evidence passed.\n";
