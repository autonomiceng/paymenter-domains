<?php
// Native saved-fact and secrecy regressions. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Price};
use Paymenter\Extensions\Servers\Domains\Services\{ObservationSchema,PricingRule};
$f=commerceFixture();extract($f);tldPanel();ObservationSchema::install();Carbon\Carbon::setTestNow($now);
$t=$tlds[0];$server=App\Models\Server::findOrFail($t->server_id);
$server->settings()->create(['key'=>'private-sentinel','type'=>'string','encrypted'=>true,'value'=>'PRIVATE-CREDENTIAL']);
$d=Domain::create(['server_id'=>$t->server_id,'registrar_key'=>$t->registrar_key,'account_fingerprint'=>$t->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder.com','sld'=>'holder','tld'=>'com','status'=>'active']);
DB::table('domains_observation_queues')->insert(['server_id'=>$t->server_id,'account_fingerprint'=>$t->account_fingerprint,'catalogue_hold'=>'read_unavailable']);
foreach([[],[Staff::COSTS],[Staff::VIEW],[Staff::VIEW,Staff::COSTS]] as $permissions){
    $u=staffUser($permissions);Auth::setUser($u);$can=in_array(Staff::VIEW,$permissions,true);$cost=$can&&in_array(Staff::COSTS,$permissions,true);
    $list=Livewire::actingAs($u)->test(ListTlds::class);$setup=Livewire::actingAs($u)->test(RegistrarSetup::class);
    if(!$can){$list->assertForbidden();$setup->assertForbidden();continue;}
    $list->assertCanSeeTableRecords($tlds);$setup->assertSuccessful()->assertSee('Enom')->assertSee('NameSilo')->assertSee('Processing');
    $detail=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSuccessful()->assertSet('data',[])
        ->assertSee('Revision history')->assertSee('Refresh failed or interrupted')->assertSee('existing domains keep their holding registrar');
    $body=$detail->html().$setup->html().json_encode($detail->snapshot).json_encode($setup->snapshot);
    foreach(['PRIVATE-','account-enom','account-namesilo',$t->account_fingerprint] as $secret){expect(!str_contains($body,$secret),'No account or credential in markup/state');}
    same($cost,str_contains($detail->html(),'8.0000 USD'),'Wholesale independently permissioned');
    expect(!str_contains(json_encode($detail->snapshot),'8.0000'),'Cost not public component state');
}
$u=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($u);
$rules=new \Paymenter\Extensions\Servers\Domains\Services\PricingRules;
$rules->saveDefaultRule($t->server_id,['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>array_fill_keys(['register','renew','transfer'],'1.00')],$prices->republish(...));
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee('Below cost · held; staff alert')->assertSee('Below cost · billed; staff alert');
$unknown=Price::where('server_id',$t->server_id)->where('action','register')->where('term',1)->sole();
$unknown->update(['wholesale'=>null,'hold_reason'=>'unknown_price','revision'=>$unknown->revision+1]);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id])->assertSee('Cost unknown · held');
expect(str_contains(json_encode(TldFacts::prices($t)),'Unknown'),'Unknown cost remains explicit');
$calls=$reads->calls;Livewire::actingAs($u)->test(RegistrarSetup::class)->assertSee('Unavailable');same($calls,$reads->calls,'No reader factory on GET');
DB::table('domains_tlds')->where('id',$tlds[1]->id)->update(['account_fingerprint'=>str_repeat('f',64)]);
Livewire::actingAs($u)->test(ListTlds::class)->assertCanNotSeeTableRecords([$tlds[1]]);
$open=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$t->id]);$u->role->update(['permissions'=>[]]);$open->call('$refresh')->assertForbidden();
expect(!TldResource::canCreate()&&!TldResource::canEdit($t)&&!TldResource::canDelete($t),'No direct model editors');
expect(!class_exists('Paymenter\\Extensions\\Servers\\Domains\\Domains',false),'No installed entry');
echo "Native TLD list/detail, registrar setup, margin labels, costs and credential secrecy passed.\n";$nativeSuccessful=true;
