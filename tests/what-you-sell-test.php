<?php
// The What you sell screen over the synthetic Enom wire; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-catalogue-wire.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\{Currency,Notification as Inbox,Server};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,SellRow,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,CatalogueAcceptances,EnomTestSendLimit,PriceFill,PriceList,PriceSync,SellRule,SellTableSchema,StaffSettingsReview as Review};

config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,
    'domains.enom_test_sends_per_day'=>500,'domains.enom_test_sends_per_minute'=>100000,'domains.hidden_endings'=>['.XYZ','adult']]);
(new Paymenter\Extensions\Servers\Domains\Domains)->installed();(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
tldPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic picker account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$GLOBALS['catalogueList']=['adult','com','net','org','xyz','com.au','trading'];
// Year one is complete. Later buckets switch .com transfers off, which must not touch the year-one cost, and .net stops at two years.
$GLOBALS['catalogueBulk']=static fn(int $years)=>[
    'adult'=>['50.00','50.00','50.00'],'com'=>['8.95','9.95',$years===1?'9.50':null],'net'=>[$years>2?null:'12.00','11.00',null],'org'=>['10.00','10.00','10.00'],
    'expensive'=>['20.00','400.00','20.00'],'com.au'=>['15.00','15.00','15.00'],'xyz'=>['1.00','1.00','1.00'],
    'dmtest01'=>['1.00','1.00','1.00'],'3rdearlyaccess'=>['1.00','1.00','1.00'],'off'=>[null,null,null]];
$commands=static fn(int $from=0)=>array_column(array_slice($GLOBALS['catalogueCalls'],$from),'Command');
$calls=static fn()=>count($GLOBALS['catalogueCalls']);
$row=static fn(string $tld)=>SellRow::where(['server_id'=>$server->id,'tld'=>$tld])->sole();
$select=static fn($page,string ...$tlds)=>$page->selectTableRecords(SellRow::where('server_id',$server->id)->whereIn('tld',$tlds)->pluck('id')->all());
$rule=static function($page,array $data)use($staff){$page->callAction('rule',$data);return $page;};

// An account that is not connected gets a way forward, and no read.
$before=$calls();
Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee('Connect a registrar account first')->assertSee('Open Registrars');
same($before,$calls(),'No connected account means no read');

Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction(setupAction('configure',$server->id))
    ->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid','token'=>'catalogue-token','reason'=>'Connect synthetic picker'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
$account=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($account);$fill=app(PriceFill::class);

// The menu shows it right below TLDs & pricing, always.
$labels=[];foreach(Filament::getCurrentPanel()->getNavigation() as $group){foreach($group->getItems() as $item){$labels[]=$item->getLabel();}}
same(1,array_search('What you sell',$labels,true)-array_search('TLDs & pricing',$labels,true),'What you sell sits directly below TLDs & pricing');
same('What you sell',WhatYouSell::getNavigationLabel(),'Plain menu label');

// The empty TLD list links to the screen.
Livewire::actingAs($staff)->test(ListTlds::class)->assertSee('Choose what you sell')->assertSeeHtml(e(WhatYouSell::getUrl()));

// Opening the page, empty or full, never talks to the registrar.
$before=$calls();
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee('No prices yet')->assertSee('use Sync now')->assertActionVisible('sync_now');
same(0,$calls()-$before,'Opening an empty page sends nothing');

// Sync now: one read per price bucket, plus the list on the first fill so TLDs without a price row still show.
$page->callAction('sync_now')->assertNotified('Prices updated');
same(['PE_GetRetailPricing','PE_GetRetailPricing','PE_GetRetailPricing','PE_GetRetailPricing','GetTLDList'],$commands($before),'First fill reads each bucket and the list once');
same(['1','2','5','10'],array_column(array_slice($GLOBALS['catalogueCalls'],$before,4),'Years'),'Years 1, 2, 5, 10');
same('1',$GLOBALS['catalogueCalls'][$before]['TLDOnly'],'TLDs only');
same(['8.9500','9.9500','9.5000','9.9500'],[$row('com')->register_cost,$row('com')->renew_cost,$row('com')->transfer_cost,$row('com')->high_cost],'Reseller costs, never the retail fields');
same(null,$row('com')->prices[2]['transfer'],'A later bucket keeps its own flag');
same(null,$row('net')->transfer_cost,'A disabled zero is no cost');same(null,$row('net')->prices[5]['register'],'A disabled bucket stays disabled');
same([null,null,null],[$row('off')->register_cost,$row('off')->renew_cost,$row('off')->high_cost],'A disabled TLD is not free');
same([null,null],[$row('trading')->register_cost,$row('trading')->prices],'A TLD on the list with no price row stays, with no cost');
same(0,SellRow::whereIn('tld',['dmtest01','3rdearlyaccess'])->count(),'Names no order could use stay out');
same(false,str_contains(json_encode($row('com')->prices),'99.00'),'No retail value is stored');

// The full page spends nothing either, shows where prices came from, and leaves out hidden TLDs.
$before=$calls();
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee('Prices from '.gmdate('M j, Y H:i',(int)PriceFill::last($binding)->filled_at).' UTC')
    ->assertSee('.com')->assertSee('.org')->assertSee('.com.au')->assertSee('.trading')->assertSee('No cost yet')->assertCanNotSeeTableRecords([$row('xyz'),$row('adult')])
    ->assertSee('USD 8.95')->assertSee('1-year cost')->assertSee('Profit');
same(0,$calls()-$before,'Opening a full page sends nothing');
// Profit shows cents. A card fee gives the stored figure more digits than that.
$server->settings()->updateOrCreate(['key'=>CardFee::KEY],['type'=>'array','encrypted'=>false,'value'=>['percent'=>'2.9','fixed'=>'0.30']]);
$html=Livewire::actingAs($staff)->test(WhatYouSell::class)->html();$server->settings()->where('key',CardFee::KEY)->delete();
expect(preg_match('/USD -?[\d,]+\.\d{2}\b/',$html)===1&&preg_match('/USD -?[\d,]+\.\d{3}/',$html)===0,'Profit shows two decimals');same('2.05',PriceList::profit('2.0524'),'Display rounds, the saved figure stays');
foreach(['independently verified','Purchase route','new import','25-second','resellertest','Verified','Reason','More years'] as $word){$page->assertDontSee($word);}
same(0,preg_match('/\bendings?\b/i',strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/si','',$page->html()))),'The screen says TLD, never the other word');
$page->set('tableSearch','.co')->assertCanSeeTableRecords([$row('com')])->assertCanNotSeeTableRecords([$row('org')]);$page->set('tableSearch','');
$page->filterTable('country')->assertCanSeeTableRecords([$row('com.au')])->assertCanNotSeeTableRecords([$row('trading')]);$page->filterTable('country',false);
$page->filterTable('no_cost')->assertCanSeeTableRecords([$row('trading')])->assertCanNotSeeTableRecords([$row('org')]);$page->filterTable('no_cost',false);

// The schedule waits a full day, then reads just the buckets: staff choices stay.
$before=$calls();$fill->daily($account);same(0,$calls()-$before,'Not due yet: Sync now counts as today');
$back=static fn(int $seconds)=>DB::table(SellTableSchema::FILLS)->where('server_id',$server->id)->update(['tried_at'=>DB::raw('tried_at - '.$seconds)]);
$back(PriceFill::EVERY_SECONDS);$fill->daily($account);
same(['PE_GetRetailPricing','PE_GetRetailPricing','PE_GetRetailPricing','PE_GetRetailPricing'],$commands($before),'One daily fill is 4 reads');

// A failed fill keeps the last good rows and tells nobody, until 3 days pass.
$inbox=static fn()=>Inbox::where('title','Prices are out of date')->count();
$good=$row('org')->getAttributes();$filled=(int)PriceFill::last($binding)->filled_at;
$GLOBALS['catalogueBulkFailed']=true;$back(PriceFill::EVERY_SECONDS);$fill->daily($account);
same($good,$row('org')->getAttributes(),'A failed fill writes nothing');same($filled,(int)PriceFill::last($binding)->filled_at,'Its time stays');
same(0,$inbox(),'One failed run alerts nobody');expect(PriceFill::last($binding)->failure!==null,'The failure is recorded');
Livewire::actingAs($staff)->test(WhatYouSell::class)->assertDontSee('Prices are out of date')
    ->callAction('sync_now')->assertNotified("Couldn't get prices");same($good,$row('org')->getAttributes(),'A failed Sync now writes nothing');
$now=app(Clock::class)->now()->getTimestamp();
DB::table(SellTableSchema::FILLS)->where('server_id',$server->id)->update(['filled_at'=>$now-PriceFill::STALE_SECONDS,'tried_at'=>$now-PriceFill::EVERY_SECONDS]);
$fill->daily($account);expect($inbox()>0,'Three days without a good fill tells staff');$sent=$inbox();
same(WhatYouSell::getUrl(),Inbox::where('title','Prices are out of date')->first()->url,'The notice opens the page');
$back(PriceFill::EVERY_SECONDS);$fill->daily($account);same($sent,$inbox(),'One notice for one stretch');
Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee('Prices are out of date')->assertSee('Those prices are still shown');
unset($GLOBALS['catalogueBulkFailed']);
Livewire::actingAs($staff)->test(WhatYouSell::class)->callAction('sync_now')->assertNotified('Prices updated');
same([null,null],[PriceFill::last($binding)->failed_at,PriceFill::last($binding)->failure],'A good fill clears the failure');
Livewire::actingAs($staff)->test(WhatYouSell::class)->assertDontSee('Prices are out of date');

// A reply that switches a price on but gives no amount is broken, so nothing from it lands.
$good=$row('com')->getAttributes();$filled=(int)PriceFill::last($binding)->filled_at;$bulk=$GLOBALS['catalogueBulk'];
foreach(['no amount'=>static fn(int $years)=>array_replace($bulk($years),['com'=>['','9.95','9.50']]),'no year one'=>static fn(int $years)=>array_diff_key($bulk($years),$years===1?['com'=>1]:[]),
    'no later year'=>static fn(int $years)=>array_diff_key($bulk($years),$years===10?['com'=>1]:[])] as $name=>$reply){
    $GLOBALS['catalogueBulk']=$reply;
    Livewire::actingAs($staff)->test(WhatYouSell::class)->callAction('sync_now')->assertNotified("Couldn't get prices");
    same($good,$row('com')->getAttributes(),'A reply with '.$name.' keeps the last good cost');same($filled,(int)PriceFill::last($binding)->filled_at,'and is not a good fill: '.$name);
}
$GLOBALS['catalogueBulk']=$bulk;

// Two reads overlap: the one started last wins, so the older read cannot land on newer prices.
// A second run cannot send inside this one's read, so this leaves what it would: its start and its newer price.
$GLOBALS['catalogueDuring']=static function()use($server){
    DB::table(SellTableSchema::FILLS)->where('server_id',$server->id)->increment('run');DB::table(SellTableSchema::ROWS)->where(['server_id'=>$server->id,'tld'=>'com'])->update(['register_cost'=>'50.0000']);
};
Livewire::actingAs($staff)->test(WhatYouSell::class)->callAction('sync_now')->assertNotified('Another price read is running');
same('50.0000',$row('com')->register_cost,'The newer read stays');
Livewire::actingAs($staff)->test(WhatYouSell::class)->callAction('sync_now')->assertNotified('Prices updated');
same('8.9500',$row('com')->register_cost,'The next read carries on');

// A refused send says what stopped it, not the count.
config(['domains.provider_send_guard'=>static function(){throw new RuntimeException('not listed');}]);$before=$calls();
$refused=static fn(string $body)=>Livewire::actingAs($staff)->test(WhatYouSell::class)->callAction('sync_now')->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't get prices")->body($body));
$earlier='Earlier reads in this sync may have been sent and counted. ';
$refused('This site does not allow that Enom request, so it was not sent. '.$earlier.'Add it to the send guard list to allow it.');
same(0,$calls()-$before,'The guard stops the read');config(['domains.provider_send_guard'=>null]);
$login=EnomTestSendLimit::login($account);
config(['domains.enom_test_sends_per_day'=>'many']);
$refused('Our test send limit setting is missing or not valid, so this Enom request was not sent. '.$earlier);
config(['domains.enom_test_sends_per_day'=>EnomTestSendLimit::usage($login)['day']]);
$refused('Our test send check stopped this Enom request before sending. '.$earlier.EnomTestSendLimit::facts($login));
same(0,$calls()-$before,'Our own cap stops the read');config(['domains.enom_test_sends_per_day'=>500]);

// Nothing is saved yet, so sell everything is only a default: the rule is what writes.
$before=$calls();$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee('Use Edit selected to add it');
same('except',SellRule::read($binding,null)['mode'],'New accounts sell every TLD nobody set');

// Only these: tick rows, Sell. Ending state lands in the guarded acceptance write. Nothing is sent.
$page->callAction('rule',['sell_new'=>'no','max_cost'=>null,'years'=>1,'transfers'=>true])->assertNotified('Saved');
$select($page,'org','com')->callTableBulkAction('edit',SellRow::whereIn('tld',['org','com'])->pluck('id')->all(),['choice'=>'sell'])->assertNotified('Saved');
$accepted=CatalogueAcceptances::load($binding);
same(['com','org'],array_keys($accepted->endings),'Only the picked TLDs are saved');
same(['register'=>[1],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true],$accepted->endings['org'],'Sell records the rule');
same('https://resellertest.enom.com/interface.asp',$accepted->registrationRoute,'The fixed TEST route is recorded without a tick box');
same(25,$accepted->registrationIntervalSeconds,'The real request interval is recorded without a tick box');
same(0,$calls()-$before,'Saving sends nothing');
$page->assertSee('Waiting for prices')->assertSee('Not for sale');

// Saving is audited.
$audited=static fn(int $id)=>collect(Review::historyRows(Server::find($id)))->where('action','Edit what you sell')->last();$entry=$audited($server->id);
same('',$entry['reason'],'No reason is needed');same((int)$staff->id,$entry['actor_id'],'Audit records who');
same(['com'=>['terms'=>[1],'transfer'=>true],'org'=>['terms'=>[1],'transfer'=>true]],$entry['input']['changes'],'Audit records what changed');
same(['old'=>['choice'=>null,'longest'=>null,'transfer'=>null],'new'=>['choice'=>'sell','longest'=>null,'transfer'=>null]],$entry['input']['rows']['org'],'Audit records the row choice');

// Longest term: one number. Terms the registrar switched off stay out, and a TLD with an exception keeps it.
$page->callTableAction('edit_terms',$row('net'),['longest'=>10,'transfer'=>true]);
same(10,$row('net')->longest,'An exception is kept on its row');
same(['net'=>['old'=>['choice'=>null,'longest'=>null,'transfer'=>null],'new'=>['choice'=>null,'longest'=>10,'transfer'=>null]]],$audited($server->id)['input']['rows'],'Audit records a term, even when the saved list stays the same');
$select($page,'net')->callTableBulkAction('edit',[$row('net')->id],['choice'=>'sell'])->assertNotified('Saved');
same([1,2,3,4],CatalogueAcceptances::load($binding)->endings['net']['register'],'Years past the last offered bucket stay out');
same([],CatalogueAcceptances::load($binding)->endings['net']['transfer'],'No transfer without a transfer cost');
$page->callTableAction('edit_terms',$row('net'),['longest'=>1,'transfer'=>true]);
same(null,$row('net')->longest,'Matching the rule clears the exception');

// Sell new TLDs: everything nobody set with a cost is saved.
$page->callAction('rule',['sell_new'=>'yes','max_cost'=>'100','years'=>1,'transfers'=>false])->assertNotified('Saved');
$accepted=CatalogueAcceptances::load($binding);
foreach(['com','net','org','com.au'] as $tld){expect(isset($accepted->endings[$tld]),'Sell everything takes .'.$tld);}
foreach(['xyz','adult','off','trading','expensive'] as $tld){expect(!isset($accepted->endings[$tld]),'Sell everything leaves out .'.$tld);}
same([],$accepted->endings['org']['transfer'],'Transfers follow the rule');
Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee('Over your limit');
$page=Livewire::actingAs($staff)->test(WhatYouSell::class);
$select($page,'com.au')->callTableBulkAction('edit',[$row('com.au')->id],['choice'=>'skip'])->assertNotified('Saved');
expect(!isset(CatalogueAcceptances::load($binding)->endings['com.au']),'A skipped TLD is off');

// Edit N selected: one save, and a field left alone stays as it is. Leave as is everywhere saves nothing.
$saves=fn()=>collect(Review::historyRows(Server::find($server->id)))->where('action','Edit what you sell')->count();
$count=$saves();$select($page,'org')->callTableBulkAction('edit',[$row('org')->id],['transfer'=>'on','longest'=>'3'])->assertNotified('Saved');
same([3,true,'sell'],[$row('org')->longest,$row('org')->transfer,$row('org')->choice],'Bulk edit lands both fields and keeps the row choice');same($count+1,$saves(),'Bulk edit is one audited save');
$select($page,'org')->callTableBulkAction('edit',[$row('org')->id],[])->assertNotified('No changes');same($count+1,$saves(),'Leave as is saves nothing');
$page->callTableAction('edit_terms',$row('org'),['longest'=>1,'transfer'=>false]);

// A saved 'all' ignored Don't sell, so it reads as Yes and its first save keeps the same TLDs on sale.
Server::findOrFail($server->id)->settings()->updateOrCreate(['key'=>SellRule::KEY],['type'=>'array','encrypted'=>false,'value'=>['mode'=>'all','max_cost'=>'100','years'=>1,'transfers'=>false]]);
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->mountAction('rule')->assertSchemaStateSet(['sell_new'=>'yes'],'mountedActionSchema0')->callMountedAction()->assertNotified('Saved');
same(['except',null],[SellRule::read($binding,null)['mode'],$row('com.au')->choice],'Saved all converts once');expect(isset(CatalogueAcceptances::load($binding)->endings['com.au']),'Conversion keeps what all was selling');
$select($page,'com.au')->callTableBulkAction('edit',[$row('com.au')->id],['choice'=>'skip'])->assertNotified('Saved');

// A price the rule was not told about never widens what is saved. Saving twice changes nothing.
$revision=CatalogueAcceptances::load($binding)->revision;
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->callAction('rule',SellRule::read($binding,null))->assertNotified('Saved');
same($revision,CatalogueAcceptances::load($binding)->revision,'The same rule writes no new acceptance');

// A cost that climbs past the limit takes the TLD off at the next save; it never turns on by itself.
DB::table(SellTableSchema::ROWS)->where(['server_id'=>$server->id,'tld'=>'com'])->update(['high_cost'=>'150.0000']);
$page->callAction('rule',SellRule::read($binding,null));expect(!isset(CatalogueAcceptances::load($binding)->endings['com']),'Over the limit is off');
DB::table(SellTableSchema::ROWS)->where(['server_id'=>$server->id,'tld'=>'com'])->update(['high_cost'=>'9.9500']);$page->callAction('rule',SellRule::read($binding,null));

// Viewers see the screen but read nothing and change nothing.
$viewer=staffUser([Staff::VIEW]);$before=$calls();$revision=CatalogueAcceptances::load($binding)->revision;
$page=Livewire::actingAs($viewer)->test(WhatYouSell::class)->assertSee('.org')->assertDontSee('1-year cost')->assertDontSee('Profit')->assertActionHidden('rule')->assertActionHidden('sync_now');
$page->call('syncNow')->call('addEnding');same($before,$calls(),'Viewers trigger no reads');same($revision,CatalogueAcceptances::load($binding)->revision,'Viewers change nothing');
foreach(['numbers','status','binding'] as $method){
    try{$page->call($method,$row('org')->id);$called=true;}catch(\Livewire\Exceptions\MethodNotFoundException){$called=false;}
    same(false,$called,'Staff without cost rights cannot call '.$method.' directly');
}
foreach(['getTableRecord'=>[(string)$row('org')->id],'getTableRecords'=>[]] as $method=>$arguments){
    $returned=json_encode($page->call($method,...$arguments)->effects['returns'][0]);expect(str_contains($returned,'"tld":"org"'),$method.' returns the record');
    foreach(['account_fingerprint','register_cost','renew_cost','transfer_cost','high_cost','prices'] as $field){expect(!str_contains($returned,$field),$method.' keeps '.$field.' from staff without cost rights');}
}

// Someone else saves first: nothing is lost, and staff are told to check and try again.
Auth::setUser($staff);$mine=Livewire::actingAs($staff)->test(WhatYouSell::class);
Livewire::actingAs($staff)->test(WhatYouSell::class)->callAction('rule',['sell_new'=>'yes','max_cost'=>'90','years'=>1,'transfers'=>false])->assertNotified('Saved');$stale=CatalogueAcceptances::load($binding)->revision;
$mine->callAction('rule',['sell_new'=>'yes','max_cost'=>'95','years'=>1,'transfers'=>false])->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't save")->body('Someone else changed this account. Check the list and try again.'));
same($stale,CatalogueAcceptances::load($binding)->revision,'A stale save writes nothing');same('90.0000',SellRule::read($binding,null)['max_cost'],'A stale save keeps the rule');

// The same for a skipped row, where only the row changes. A price read in between stops nobody.
$mine=Livewire::actingAs($staff)->test(WhatYouSell::class);$revision=CatalogueAcceptances::load($binding)->revision;
Livewire::actingAs($staff)->test(WhatYouSell::class)->callTableAction('edit_terms',$row('com.au'),['longest'=>5,'transfer'=>false])->assertNotified('Saved');
same($revision,CatalogueAcceptances::load($binding)->revision,'Only the row changed');
$mine->callTableAction('edit_terms',$row('com.au'),['longest'=>2,'transfer'=>false])->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't save")->body('Someone else changed this account. Check the list and try again.'));
same(5,$row('com.au')->longest,'A stale row edit overwrites nothing');
$mine=Livewire::actingAs($staff)->test(WhatYouSell::class);$fill->run($account);
$mine->callTableAction('edit_terms',$row('com.au'),['longest'=>2,'transfer'=>false])->assertNotified('Saved');same(2,$row('com.au')->longest,'A price read does not make a save stale');

// A save from before this screen has no order setup. Any save catches up, and reads nothing.
$accepted=CatalogueAcceptances::load($binding);
DB::transaction(fn()=>CatalogueAcceptances::saveLocked(new CatalogueAcceptance($binding,$accepted->revision,$accepted->endings)));
expect(str_ends_with(TldFacts::acceptance($account->fresh()),'Save What you sell to take orders'),'The account page says what to do');$before=$calls();
Livewire::actingAs($staff)->test(WhatYouSell::class)->callAction('rule',SellRule::read($binding,null))->assertNotified('Saved');
same('https://resellertest.enom.com/interface.asp',CatalogueAcceptances::load($binding)->registrationRoute,'One save records the order setup');same($before,$calls(),'Catching up reads nothing');

// A list saved before this rule stays exactly as it was: every sold TLD keeps its years and transfers.
$old=Server::create(['name'=>'Synthetic saved list','type'=>'server','extension'=>'Domains','enabled'=>true]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$old->id])->mountAction(setupAction('configure',$old->id))
    ->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid-2','token'=>'catalogue-token','reason'=>'Connect synthetic blank'])->callMountedAction()->assertHasNoActionErrors();
$oldBinding=CatalogueAcceptances::binding(Account::where('server_id',$old->id)->sole());
CatalogueAcceptances::save($oldBinding,0,['org'=>['register'=>[1,3],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true]]);
$page=Livewire::actingAs($staff)->test(WhatYouSell::class,['account'=>$old->id]);
same('only',SellRule::read($oldBinding,CatalogueAcceptances::load($oldBinding))['mode'],'A saved list means only these');
$kept=SellRow::where(['server_id'=>$old->id,'tld'=>'org'])->sole();same(['sell',3,true],[$kept->choice,$kept->longest,$kept->transfer],'Its row copies what is sold');
$endings=CatalogueAcceptances::load($oldBinding)->endings;
$page->callAction('rule',['sell_new'=>'no','max_cost'=>null,'years'=>1,'transfers'=>false])->assertNotified('Saved');
same($endings,CatalogueAcceptances::load($oldBinding)->endings,'Saving a rule keeps what is sold, even non-contiguous years');

// With no list to read, staff type the TLD instead. Nothing is sent.
config(['domains.enom_catalogue.enabled'=>false]);$before=$calls();
Livewire::actingAs($staff)->test(WhatYouSell::class,['account'=>$old->id])->assertSee('Add TLD')->set('newEnding',' .INFO ')->call('addEnding')->assertNotified('Saved')->assertSee('.info')->set('newEnding','xyz')->call('addEnding')->assertNotified();
same([1],CatalogueAcceptances::load($oldBinding)->endings['info']['register'],'A typed TLD is saved');expect(!isset(CatalogueAcceptances::load($oldBinding)->endings['xyz']),'A hidden one is not');
same($before,$calls(),'Typing a TLD reads nothing');config(['domains.enom_catalogue.enabled'=>true]);

// Holders keep renewals when a TLD is turned off, and a hidden one stays on the list for them.
Domain::create(['server_id'=>$server->id,'registrar_key'=>'enom','account_fingerprint'=>$account->account_fingerprint,'user_id'=>900,'service_id'=>900,'domain'=>'example.org','reservation_name'=>'example.org','sld'=>'example','tld'=>'org','status'=>'active']);
$page=Livewire::actingAs($staff)->test(WhatYouSell::class,['account'=>$server->id]);
$select($page,'org')->callTableBulkAction('edit',[$row('org')->id],['choice'=>'skip'])->assertNotified('Saved');
same(['register'=>[],'renew'=>[1],'transfer'=>[],'registry'=>false,'renewal_safe'=>true],CatalogueAcceptances::load($binding)->endings['org'],'Holders keep renewals');
$page->assertSee('Renewals only');
config(['domains.hidden_endings'=>['org']]);Livewire::actingAs($staff)->test(WhatYouSell::class,['account'=>$server->id])->assertSee('.org')->assertSee('Renewals only');config(['domains.hidden_endings'=>['.XYZ','adult']]);

// The checklist links here.
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
$page->assertSee('What you sell')->assertDontSee('Choose what you sell')->assertSeeHtml(e(WhatYouSell::getUrl(['account'=>$server->id])))->assertDontSee('Supported TLDs')->assertDontSee('Edit supported TLDs');
expect(!in_array('acceptance',staffSetupActions(),true),'The dialog is no longer a setup action');

// A saved TLD with prices that cannot sell says why, and links to where it is fixed.
app(PriceSync::class)->run(Account::where('server_id',$old->id)->sole(),app(Clock::class),null);Tld::where(['server_id'=>$old->id,'tld'=>'org'])->sole()->update(['enabled'=>false]);
Livewire::actingAs($staff)->test(WhatYouSell::class,['account'=>$old->id])->assertSee('Stopped by staff. Open TLDs &amp; pricing',false)->assertSeeHtml('href="'.e(ListTlds::getUrl()).'"');

// The whole list: 616 rows stay fast and fit our own limits, on a save and on a page load.
$rows=array_fill_keys(array_slice(array_merge(...array_map(static fn(string $a)=>array_map(static fn(string $b)=>'t'.$a.$b,range('a','z')),range('a','z'))),0,616),['9.00','9.00','9.00']);
$GLOBALS['catalogueBulk']=static fn()=>$rows;
$large=Server::create(['name'=>'Synthetic full list','type'=>'server','extension'=>'Domains','enabled'=>true]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$large->id])->mountAction(setupAction('configure',$large->id))
    ->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid-3','token'=>'catalogue-token','reason'=>'Connect synthetic full list'])->callMountedAction()->assertHasNoActionErrors();
$largeBinding=CatalogueAcceptances::binding(Account::where('server_id',$large->id)->sole());
$page=Livewire::actingAs($staff)->test(WhatYouSell::class,['account'=>$large->id])->callAction('sync_now')->assertNotified('Prices updated');
$started=hrtime(true);$page->callAction('rule',['sell_new'=>'yes','max_cost'=>null,'years'=>1,'transfers'=>true])->assertNotified('Saved');$saved=(hrtime(true)-$started)/1e9;
same(616,count(CatalogueAcceptances::load($largeBinding)->endings),'Every TLD fits in one account');
$entry=$audited($large->id);
same(['added'=>616,'removed'=>0,'rows'=>0,'cleared'=>0],$entry['input']['counts'],'A whole-list save records its counts');expect(!isset($entry['input']['changes']),'and not the list');
$started=hrtime(true);Livewire::actingAs($staff)->test(WhatYouSell::class,['account'=>$large->id])->assertSee('Waiting for prices');$opened=(hrtime(true)-$started)/1e9;
echo sprintf("616 rows: save %.2fs, open %.2fs\n",$saved,$opened);expect($saved<10&&$opened<5,'The full list stays fast');
// A batch stays in the audit as a count once it passes the limit, like a whole-list save.
$batch=SellRow::where('server_id',$large->id)->where('tld','like','t%')->limit(60)->pluck('id')->all();
$page->selectTableRecords($batch)->callTableBulkAction('edit',$batch,['choice'=>'skip'])->assertNotified('Saved');$entry=$audited($large->id);
same(60,$entry['input']['counts']['rows'],'A long batch records its count');expect(!isset($entry['input']['rows']),'and not each row');
// Twice the rows on screen means no more queries.
$queries=static function(int $perPage)use($page):int{DB::enableQueryLog();DB::flushQueryLog();$page->set('tableRecordsPerPage',$perPage);$count=count(DB::getQueryLog());DB::disableQueryLog();return $count;};
$queries(100);$fifty=$queries(50);same($fifty,$queries(100),'Queries do not grow with rows');echo "Page render: $fifty queries\n";
$page->assertSee('Waiting for prices');
echo "What you sell passed.\n";$nativeSuccessful=true;
