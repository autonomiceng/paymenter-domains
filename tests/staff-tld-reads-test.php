<?php
// Native saved-fact and secrecy regressions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/staff-tld-filter-lookalikes.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price};
use Paymenter\Extensions\Servers\Domains\Services\{CheckoutEligibility,ObservationSchema,PricingRule,PublicationEligibility,Storefront};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$f=commerceFixture();extract($f);tldPanel();ObservationSchema::install();Carbon\Carbon::setTestNow($now);
$t=$tlds[0];$server=App\Models\Server::findOrFail($t->server_id);
$server->settings()->create(['key'=>'private-sentinel','type'=>'string','encrypted'=>true,'value'=>'PRIVATE-CREDENTIAL']);
$d=Domain::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);
DB::table('domains_observation_queues')->insert(['server_id'=>$t->server_id,'account_fingerprint'=>$t->account_fingerprint,'catalogue_hold'=>'read_unavailable']);
foreach([[],[Staff::COSTS],[Staff::VIEW],[Staff::VIEW,Staff::COSTS]] as $permissions){
    $u=staffUser($permissions);Auth::setUser($u);$can=in_array(Staff::VIEW,$permissions,true);$cost=$can&&in_array(Staff::COSTS,$permissions,true);
    $list=Livewire::actingAs($u)->test(ListTlds::class);$setup=Livewire::actingAs($u)->test(RegistrarSetup::class);
    if(!$can){$list->assertForbidden();$setup->assertForbidden();continue;}
    $list->assertCanSeeTableRecords($tlds);$setup->assertSuccessful()->assertSee('Enom')->assertSee('NameSilo')->assertSee('Processing');
    $detail=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSuccessful()->assertSet('data',[])
        ->assertSee('History')->assertSee('Sync failed')->assertSee('Registrar read failed')->assertSee('Existing domains stay with their registrar');
    $body=$detail->html().$setup->html().json_encode($detail->snapshot).json_encode($setup->snapshot);
    foreach(['PRIVATE-','account-enom','account-namesilo',$t->account_fingerprint] as $secret){expect(!str_contains($body,$secret),'No account or credential in markup/state');}
    same($cost,str_contains($detail->html(),'USD 8.00'),'Wholesale independently permissioned');
    expect(!str_contains(json_encode($detail->snapshot),'8.0000'),'Cost not public component state');
}
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);
// Renewal-off feedback must distinguish a disabled switch from missing retained prices.
$clock=new FakeClock($now);$store=new Storefront($sellers,$prices,$native,$bindings,new CheckoutEligibility($bindings,$clock),$clock);
$savedPrices=static fn()=>DB::table('domains_prices')->where('server_id',$t->server_id)->orderBy('id')->get()->map(fn($p)=>(array)$p)->all();
$retained=$savedPrices();same(4,count($retained),'Current renewal, transfer and both registration terms retained');
foreach($retained as $p){
    $prices->current($t->server_id,$t->account_fingerprint,$t->tld,$p['action'],(int)$p['term'],'USD',$now);
    if($p['action']!=='renew'){PublicationEligibility::require($t,$p['action'],(int)$p['term']);}
}
$healthy=['label'=>'For sale','tone'=>'success','reason'=>null];
same($healthy,TldFacts::salesState($t->fresh()),'Accepted renewal-enabled ending is price eligible');
$wrong=clone $t;$wrong->account_fingerprint=str_repeat('f',64);
DB::table('domains_prices')->where('server_id',$t->server_id)->update(['account_fingerprint'=>$wrong->account_fingerprint]);
same(['label'=>'Not for sale','tone'=>'gray','reason'=>'Sync TLD prices needed'],TldFacts::salesState($wrong),'Matching TLD/price corruption cannot borrow the current account acceptance');
DB::table('domains_prices')->where('server_id',$t->server_id)->update(['account_fingerprint'=>$t->account_fingerprint]);
same(['com'],array_column($store->endings(),'tld'),'Healthy selected ending is on sale');
$calls=$reads->calls;$t->update(['renew_enabled'=>false]);
$renewalOff=['label'=>'Not for sale','tone'=>'gray','reason'=>'Renewals off for this registrar'];
same($renewalOff,TldFacts::salesState($t->fresh()),'Renewal-off explains the switch even with valid current prices');
Livewire::actingAs($u)->test(ListTlds::class)->assertSee('Not for sale')->assertSee($renewalOff['reason']);
same([],$store->endings(),'Renewal-off withdraws public registration and transfer sales');
same('held',$store->check('example.com')['status'],'Renewal-off search offers no sale');
foreach(['register','transfer'] as $action){commerceReject(fn()=>$store->offer('com',$action,1));}
same($retained,$savedPrices(),'Renewal-off staff and public reads retain exact price rows and freshness');
same($calls,$reads->calls,'Renewal-off feedback and sales checks make no provider reads');
$paused=['label'=>'Not for sale','tone'=>'gray','reason'=>'Stopped by staff'];
$t->update(['enabled'=>false]);same($paused,TldFacts::salesState($t->fresh()),'Overall pause takes precedence over renewal-off');
$t->update(['enabled'=>true,'register_enabled'=>false,'transfer_enabled'=>false]);
same($paused,TldFacts::salesState($t->fresh()),'Both acquisition switches off take pause precedence');
$t->update(['register_enabled'=>true,'transfer_enabled'=>true,'renew_enabled'=>true]);
same($healthy,TldFacts::salesState($t->fresh()),'Restored renewal-enabled contrast is price eligible');
same(['com'],array_column($store->endings(),'tld'),'Renewal-enabled contrast restores public sales');
same($retained,$savedPrices(),'Switch changes preserve all saved prices');
$calls=$reads->calls;staffTldFilterLookalikes($u,$tlds);same($calls,$reads->calls,'TLD selectors use saved facts only');
foreach (['12.0000'=>'USD 12.00','12.3000'=>'USD 12.30','12.3450'=>'USD 12.345','12.3456'=>'USD 12.3456','0.0000'=>'USD 0.00'] as $amount=>$label) { same($label,TldFacts::money($amount,4),'Wholesale precision stays exact while redundant zeros are trimmed'); }
$rules=new \Paymenter\Extensions\Servers\Domains\Services\PricingRules;
$rules->saveDefaultRule($t->server_id,['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>array_fill_keys(['register','renew','transfer'],'1.00')],$prices->republish(...));
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee('Below cost')->assertSee('Registrations and transfers below cost are held. Renewals are billed.');
foreach(TldFacts::prices($t->fresh()) as $row){expect(in_array('below_cost',$row['flags'],true),'Below cost flagged: '.$row['action'].' '.$row['term']);}
same(['label'=>'Not for sale','tone'=>'gray','reason'=>'Below cost'],TldFacts::salesState($t->fresh()),'Below-cost registration is held');
$unknown=Price::where('server_id',$t->server_id)->where('action','register')->where('term',1)->sole();
$unknown->update(['wholesale'=>null,'hold_reason'=>'unknown_price','revision'=>$unknown->revision+1]);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee('Not for sale')->assertSee('Cost missing');
$row=collect(TldFacts::prices($t->fresh()))->first(fn($r)=>$r['action']==='register'&&$r['term']===1);
expect(in_array('cost_missing',$row['flags'],true)&&$row['cost']===null,'Unknown cost remains explicit');
$calls=$reads->calls;Livewire::actingAs($u)->test(RegistrarSetup::class)->assertSee('Unavailable');same($calls,$reads->calls,'No reader factory on GET');
DB::table('domains_tlds')->where('id',$tlds[1]->id)->update(['account_fingerprint'=>str_repeat('f',64)]);
Livewire::actingAs($u)->test(ListTlds::class)->assertCanNotSeeTableRecords([$tlds[1]]);
$open=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id]);$u->role->update(['permissions'=>[]]);$open->call('$refresh')->assertForbidden();
expect(!TldResource::canCreate()&&!TldResource::canEdit($t)&&!TldResource::canDelete($t),'No direct model editors');
expect(!class_exists('Paymenter\\Extensions\\Servers\\Domains\\Domains',false),'No installed entry');
echo "Native TLD list/detail, registrar setup, margin labels, costs and credential secrecy passed.\n";$nativeSuccessful=true;
