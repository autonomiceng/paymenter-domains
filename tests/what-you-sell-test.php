<?php
// The What you sell screen over the synthetic Enom wire; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/enom-catalogue-wire.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\{Currency,Server};
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Cache,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,WhatYouSell};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ListTlds;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Data\CatalogueAcceptance;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price,Tld};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances,StaffSettingsReview as Review};

config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,
    'domains.enom_test_sends_per_day'=>500,'domains.enom_test_sends_per_minute'=>100000,'domains.hidden_endings'=>['.XYZ','adult']]);
(new Paymenter\Extensions\Servers\Domains\Domains)->installed();(new Paymenter\Extensions\Servers\Domains\Domains)->boot();
tldPanel();$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$server=Server::create(['name'=>'Synthetic picker account','type'=>'server','extension'=>'Domains','enabled'=>true]);
$GLOBALS['catalogueList']=['adult','com','net','org','xyz','com.au'];$GLOBALS['catalogueMaximum']=['org'=>3];
$commands=static fn(int $from=0)=>array_column(array_slice($GLOBALS['catalogueCalls'],$from),'Command');
$calls=static fn()=>count($GLOBALS['catalogueCalls']);

// An account that is not connected gets a way forward, and no read.
$before=$calls();
Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee('Connect a registrar account first')->assertSee('Open Registrars');
same($before,$calls(),'No connected account means no read');

Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction(setupAction('configure',$server->id))
    ->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid','token'=>'catalogue-token','reason'=>'Connect synthetic picker'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved');
$account=Account::where('server_id',$server->id)->sole();$binding=CatalogueAcceptances::binding($account);

// The menu shows it right below TLDs & pricing, always.
$labels=[];foreach(Filament::getCurrentPanel()->getNavigation() as $group){foreach($group->getItems() as $item){$labels[]=$item->getLabel();}}
same(1,array_search('What you sell',$labels,true)-array_search('TLDs & pricing',$labels,true),'What you sell sits directly below TLDs & pricing');
same('What you sell',WhatYouSell::getNavigationLabel(),'Plain menu label');

// The empty TLD list links to the screen.
Livewire::actingAs($staff)->test(ListTlds::class)->assertSee('Choose what you sell')->assertSeeHtml(e(WhatYouSell::getUrl()));

// One GetTLDList call fills the whole list. Hidden endings stay out. No terms or prices are read.
$before=$calls();
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->call('loadList');
same(['GetTLDList'],$commands($before),'Opening the screen costs one list call');
$page->assertSee('.com')->assertSee('.net')->assertSee('.org')->assertSee('.com.au')->assertDontSee('.xyz')->assertDontSee('.adult');
foreach(['independently verified','Purchase route','new import','25-second','resellertest','Verified','Reason'] as $word){$page->assertDontSee($word);}

// A reload spends nothing. Refresh list is the one way to read again.
$before=$calls();
Livewire::actingAs($staff)->test(WhatYouSell::class)->call('loadList')->assertSee('.org');
same($before,$calls(),'A reload reads the saved list');
$page->call('refreshList');same(['GetTLDList'],$commands($before),'Refresh list reads once more');

// Search narrows the list.
$page->set('tableSearch','or')->assertSee('.org')->assertDontSee('.net');$page->set('tableSearch','');

// Turning an ending on reads its years, once, and only for that ending.
$before=$calls();
$page->call('toggleEnding','org')->assertSet('chosen.org.terms',[1])->assertSet('chosen.org.transfer',false)->assertSet('years.org',[1,2,3]);
same(['GetTLDDetails'],$commands($before),'Terms are read only for the ending turned on');
same('org',$GLOBALS['catalogueCalls'][$before]['TLD'],'Terms read names the ending');
$page->call('toggleEnding','org')->call('toggleEnding','org');same(1,$calls()-$before,'Turning it off and on again reads nothing');
$page->assertSet('chosen.org.terms',[1]);

// Ticking years and transfers makes no calls.
$before=$calls();
$page->call('toggleYear','org',2)->assertSet('chosen.org.terms',[1,2])->call('toggleTransfer','org')->assertSet('chosen.org.transfer',true);
same($before,$calls(),'Ticking years and transfers makes no calls');
// Unticking the last year turns the ending off.
$page->call('toggleEnding','net')->call('toggleYear','net',1)->assertSet('chosen.net',null);

// Save takes no reason, no ticks about routes, and reads prices for the chosen ending only.
$before=$calls();
$page->call('save')->assertNotified('Saved');
$accepted=CatalogueAcceptances::load($binding);
same(['register'=>[1,2],'renew'=>[1],'transfer'=>[1],'registry'=>true,'renewal_safe'=>true],$accepted->endings['org'],'Save records the ticked terms');
same(['org'],array_keys($accepted->endings),'Only the ticked ending is saved');
$reloaded=Livewire::actingAs($staff)->test(WhatYouSell::class);
same(0,preg_match('/wire:(?:click|target)="[^"]*@js\(/',$reloaded->html()),'Saved-year controls render executable Livewire parameters');
same('https://resellertest.enom.com/interface.asp',$accepted->registrationRoute,'The fixed TEST route is recorded without a tick box');
same(25,$accepted->registrationIntervalSeconds,'The real request interval is recorded without a tick box');
same(['org'],array_values(array_unique(array_filter(array_column(array_slice($GLOBALS['catalogueCalls'],$before),'TLD')))),'Saving reads only the ending turned on');
same(0,count(array_filter($commands($before),fn($c)=>$c==='GetTLDList'))>1?1:0,'Saving reads the list at most once');
expect(Price::where(['server_id'=>$server->id,'tld'=>'org','action'=>'register','term'=>2])->exists(),'Saving brings in the prices');
same([1,2],[Tld::where(['server_id'=>$server->id,'tld'=>'org'])->sole()->min_years,Tld::where(['server_id'=>$server->id,'tld'=>'org'])->sole()->max_years],'Years land on the TLD');

// Saving is audited.
$entry=array_values(array_filter(Review::historyRows(Server::find($server->id)),fn($row)=>$row['action']==='Edit what you sell'))[0];
same('Edit what you sell',$entry['action'],'Audit names the save');same('',$entry['reason'],'No reason is needed');same((int)$staff->id,$entry['actor_id'],'Audit records who');
same(['org'=>['terms'=>[1,2],'transfer'=>true]],$entry['input']['changes'],'Audit records what changed');

// A second save with nothing changed offers nothing.
$page->assertActionDisabled('save');

// Saved endings stay on the list even if the list later omits them. A hidden one is kept for renewals and can't be ticked again.
Cache::flush();$GLOBALS['catalogueList']=['com'];config(['domains.hidden_endings'=>['org']]);
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->call('loadList')->assertSee('.org')->assertSee('.com')->assertSee('Renewals only')->call('toggleEnding','org');
same(null,$page->get('chosen.org'),'A hidden TLD stays unticked');
expect(preg_match('/aria-label="Sell \.org"\s+disabled/',$page->html())===1,'Its tick is off');
$GLOBALS['catalogueList']=['adult','com','net','org','xyz','com.au'];config(['domains.hidden_endings'=>['.XYZ','adult']]);

// A failed read says so plainly, keeps saved endings and saves nothing new.
Cache::flush();$GLOBALS['catalogueListFailed']=true;
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->call('loadList')->assertSee("Couldn't read the list")->assertSee('.org')->assertDontSee('.com.au');
unset($GLOBALS['catalogueListFailed']);$page->call('refreshList')->assertDontSee("Couldn't read the list")->assertSee('.com.au');

// Our own daily cap holds: the screen reads nothing past it and says so.
Cache::flush();$used=Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimit::usage((string)$account->stable_identity)['day'];
config(['domains.enom_test_sends_per_day'=>$used]);$before=$calls();
Livewire::actingAs($staff)->test(WhatYouSell::class)->call('loadList')->assertSee("Couldn't read the list")->assertSee('.org');
same($before,$calls(),'The cap stops the read');config(['domains.enom_test_sends_per_day'=>500]);

// Viewers see the screen but read nothing and change nothing.
Cache::flush();$viewer=staffUser([Staff::VIEW]);$before=$calls();
$page=Livewire::actingAs($viewer)->test(WhatYouSell::class)->call('loadList')->assertSee('.org')->assertActionHidden('save')->assertActionHidden('refresh_list')->call('toggleEnding','net');
same($before,$calls(),'Viewers trigger no reads');same(null,$page->get('chosen.net'),'Viewers change nothing');

// Staff who cannot sync still save; the note says how prices arrive.
$saver=staffUser(array_values(array_diff(array_keys(Staff::permissions()),[Review::permission('import')])));$GLOBALS['catalogueMaximum']=[];$before=$calls();
Livewire::actingAs($saver)->test(WhatYouSell::class)->call('loadList')->call('toggleEnding','net')->call('save')->assertNotified('Saved');
expect(isset(CatalogueAcceptances::load($binding)->endings['net']),'Saved without the sync permission');
same(false,Price::where(['server_id'=>$server->id,'tld'=>'net'])->exists(),'No sync without its permission');Auth::setUser($staff);

// No prices yet: Missing says what to do, with the link, in the same words staff see elsewhere.
$unpriced=Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee(Missing::LABEL)->assertSee('Needs a price sync')->assertDontSee('Needs prices')->assertSeeHtml(e(TldResource::getUrl('index')));
same(0,preg_match('/\bendings?\b/i',strip_tags(preg_replace('/<(script|style)\b.*?<\/\1>/si','',$unpriced->html()))),'The screen says TLD, never the other word');

// Nothing chosen yet: Missing says what to do, with the link.
$blank=Server::create(['name'=>'Synthetic blank account','type'=>'server','extension'=>'Domains','enabled'=>true]);
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$blank->id])->mountAction(setupAction('configure',$blank->id))
    ->setActionData(['registrar'=>'enom','environment'=>'test','uid'=>'catalogue-uid-2','token'=>'catalogue-token','reason'=>'Connect synthetic blank'])->callMountedAction()->assertHasNoActionErrors();
Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$blank->id])->assertSeeInOrder(['What you sell','Missing','Choose the TLDs you sell.'])->assertSee('Choose what you sell')->assertSeeHtml(e(WhatYouSell::getUrl(['account'=>$blank->id])));

// With no list to read, staff type the ending instead. Nothing is sent.
config(['domains.enom_catalogue.enabled'=>false]);$before=$calls();
Livewire::actingAs($staff)->test(WhatYouSell::class)->assertSee('Add TLD')->set('newEnding',' .INFO ')->call('addEnding')->assertSet('chosen.info.terms',[1])->assertSee('.info')->set('newEnding','xyz')->call('addEnding')->assertSet('chosen.xyz',null)->assertNotified();
same($before,$calls(),'Typing an ending reads nothing');config(['domains.enom_catalogue.enabled'=>true]);

// Someone else saves first: nothing is lost, and staff are told to check and save again.
$mine=Livewire::actingAs($staff)->test(WhatYouSell::class)->call('toggleEnding','net');
Livewire::actingAs($staff)->test(WhatYouSell::class)->call('toggleYear','org',1)->call('save')->assertNotified('Saved');$stale=CatalogueAcceptances::load($binding)->revision;
$mine->call('save')->assertNotified(\Filament\Notifications\Notification::make()->danger()->title("Couldn't save")->body('Someone else changed this account. Your ticks are still here. Check them and save again.'))->assertSet('chosen.net',null);
same($stale,CatalogueAcceptances::load($binding)->revision,'A stale save writes nothing');$mine->call('save')->assertNotified('Saved');

// A save from before this screen has no order setup. Save stays open so one click catches up, and reads nothing.
$accepted=CatalogueAcceptances::load($binding);
DB::transaction(fn()=>CatalogueAcceptances::saveLocked(new CatalogueAcceptance($binding,$accepted->revision,$accepted->endings)));
expect(str_ends_with(TldFacts::acceptance($account->fresh()),'Save What you sell to take orders'),'The account page says what to do');$before=$calls();
Livewire::actingAs($staff)->test(WhatYouSell::class)->assertActionEnabled('save')->call('save')->assertNotified('Saved')->assertActionDisabled('save');
same('https://resellertest.enom.com/interface.asp',CatalogueAcceptances::load($binding)->registrationRoute,'One save records the order setup');same($before,$calls(),'Catching up reads nothing');

// The old dialog is gone: the checklist links here.
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$server->id]);
$page->assertSee('What you sell')->assertDontSee('Choose what you sell')->assertSeeHtml(e(WhatYouSell::getUrl(['account'=>$server->id])))->assertDontSee('Supported TLDs')->assertDontSee('Edit supported TLDs');
expect(!in_array('acceptance',staffSetupActions(),true),'The dialog is no longer a setup action');

// Holders keep renewals when an ending is turned off.
$tld=Tld::where(['server_id'=>$server->id,'tld'=>'org'])->sole();
Domain::create(['server_id'=>$server->id,'registrar_key'=>'enom','account_fingerprint'=>$account->account_fingerprint,'user_id'=>900,'service_id'=>900,'domain'=>'example.org','reservation_name'=>'example.org','sld'=>'example','tld'=>'org','status'=>'active']);
$page=Livewire::actingAs($staff)->test(WhatYouSell::class)->call('loadList')->call('toggleEnding','org')->call('save')->assertNotified('Saved');
same(['register'=>[],'renew'=>[1],'transfer'=>[],'registry'=>false,'renewal_safe'=>true],CatalogueAcceptances::load($binding)->endings['org'],'Holders keep renewals');
$page->assertSee('Renewals only');
echo "What you sell passed.\n";$nativeSuccessful=true;
