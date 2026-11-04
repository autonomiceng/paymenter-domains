<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture is needed to render the real Filament table and its paginator and permission checks.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\Server;
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SellTable,Staff,StaffGrants,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{StaffCatalogue,StaffCommands,Writer,Projection};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{PriceList,StaffSettingsReview as Review};
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/StaffCatalogue.php'),'Staff tables and CSV need the shared offer snapshot adapter');
require __DIR__.'/Support/offer-commerce.php';
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$source=new StaffCatalogue($ports,$clock);
$list=new class extends PriceList {public StaffCatalogue $source;protected function catalogue():?StaffCatalogue{return $this->source;}};$list->source=$source;app()->instance(PriceList::class,$list);
$commands=new StaffCommands(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),$bindings,static fn()=>null);
$t=Tld::find($tlds[0]->id);$r=new Review($actor->id,$binding->serverId,$t->id,'override',Review::ticket($actor->id,$binding->serverId,$t->id,'override',StaffCommands::stamp($binding->serverId,$t->id)),'Synthetic read review');
$commands->run($r,['mode'=>'fixed','fixed_register'=>'12.00','fixed_renew'=>'14.00','fixed_transfer'=>'13.00']);
$matrix=$list->read();same('12.00',$matrix['rows'][0]['prices']['register']['price'],'CSV matrix reads the reviewed Staff snapshot');
expect(str_contains($list->csv(),'12.00'),'CSV exports reviewed price');
$facts=$source->page(collect([$t->fresh()]));same('For sale',$facts[$t->id]['sales']['label'],'TLD label uses shared evaluator');
// The old cost and acceptance columns cannot change the new Staff projection.
DB::table('domains_prices')->where('server_id',$binding->serverId)->update(['wholesale'=>'999.0000']);
same('6.0000',$source->matrix(collect([$t->fresh()]),1)['rows'][0]['prices']['register']['cost'],'Normalized observation supplies cost, not obsolete columns');
$key=Review::ticket($actor->id,$binding->serverId,$t->id,'override',StaffCommands::stamp($binding->serverId,$t->id));
$commands->run(new Review($actor->id,$binding->serverId,$t->id,'override',$key,'Synthetic below cost'),['mode'=>'fixed','fixed_register'=>'2.00','fixed_renew'=>'14.00','fixed_transfer'=>'13.00']);
$below=$source->matrix(collect([$t->fresh()]),1,'below_cost');same('2.00',$below['rows'][0]['prices']['register']['price'],'Below-cost filter keeps the exact saved staff retail');commerceReject(fn()=>$storefront->offer('com','register',1));
$key=Review::ticket($actor->id,$binding->serverId,$t->id,'override',StaffCommands::stamp($binding->serverId,$t->id));$commands->run(new Review($actor->id,$binding->serverId,$t->id,'override',$key,'Restore synthetic price'),['mode'=>'fixed','fixed_register'=>'12.00','fixed_renew'=>'14.00','fixed_transfer'=>'13.00']);
// All accounts + No cost yet: 25 displayed pairs must not expand into 5 x 25 snapshots.
$multi=[];$multiPage=[];$identity=$t->fresh()->getRawOriginal();unset($identity['id']);$rate=(array)DB::table('domains_prices')->where('server_id',$binding->serverId)->where('action','register')->where('term',1)->first();unset($rate['id']);
for($a=0;$a<5;$a++){
    $server=Server::create(['name'=>'Synthetic page account '.$a,'type'=>'server','extension'=>'Domains','enabled'=>true]);
    $b=$bindings->configure($server->id,'enom',\Paymenter\Extensions\Servers\Domains\Data\Environment::Test,['token'=>'page-account-'.$a]);$multi[$server->id]=$b;
    for($i=0;$i<25;$i++){$name='page'.chr(97+$i);$pair=['server_id'=>$b->serverId,'registrar_key'=>$b->registrarKey,'account_fingerprint'=>$b->fingerprint,'tld'=>$name];DB::table('domains_tlds')->insert([...$identity,...$pair]);if($a>0){DB::table('domains_prices')->insert([...$rate,...$pair]);}}
}
$multiTable=new SellTable($multi,1,true,static function()use(&$multiPage){return $multiPage;},true,$source);
$multiPager=$multiTable->rows(null)->whereNull('register_cost')->orderBy('tld')->paginate(25);$multiPage=$multiPager->items();same(25,$multiPager->total(),'No-cost filter displays only the one account without costs');
foreach($multiPage as $row){$multiTable->status($row);expect($multiTable->tldId($row)!==null,'Each displayed pair retains its own converted identity');}
same(25,count($multiTable->saved()['rows']),'Bounded saved-price snapshot contains only the displayed account/ending pairs');
$seed=$t->fresh()->getRawOriginal();unset($seed['id']);$batch=[];
for($i=0;$i<598;$i++){$batch[]=[...$seed,'tld'=>'scale'.chr(97+intdiv($i,26)).chr(97+$i%26)];}
foreach(array_chunk($batch,100) as $chunk){DB::table('domains_tlds')->insert($chunk);}
$before=\Illuminate\Support\Facades\Schema::hasTable('domains_sell_rows');same(false,$before,'Retired sales store stays absent');$writes=0;DB::listen(static function($q)use(&$writes):void {if(preg_match('/^(insert|update|delete)/i',$q->sql)){$writes++;}});
$page=[];$table=new SellTable([$binding->serverId=>$binding],1,true,static function()use(&$page){return $page;},false,$source);
DB::enableQueryLog();DB::flushQueryLog();
$pager=$table->rows(null)->orderBy('tld')->paginate(25);$page=$pager->items();
foreach($page as $row){$table->status($row);$table->terms($row);$table->cell($row,'register','price');}
$count=count(DB::getQueryLog());DB::disableQueryLog();
same(599,$pager->total(),'Converted identities paginate without shadow sales rows');expect($count<100,'Bounded 25-row Staff page, got '.$count.' queries');
same($before,\Illuminate\Support\Facades\Schema::hasTable('domains_sell_rows'),'Staff reads never recreate the retired store');same(0,$writes,'Staff table/price reads have no writes');
same(1,$table->rows('com')->count(),'Focused ending remains a query filter');same(598,$table->rows(null)->where('tld','like','scale%')->count(),'Search remains a query filter');
// Render the real Filament table against the converted query, including search, filters and paging.
$GLOBALS['staffSource']=$source;
class OfferStaffPage extends \Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell
{protected function catalogue():?StaffCatalogue{return $GLOBALS['staffSource'];}
// Blade calls binding() on the concrete component; expose the inherited private helper in this fixture only.
public function binding():?\Paymenter\Extensions\Servers\Domains\Data\AccountBinding{return $this->account?\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$this->account)->sole()):null;}}
\Livewire\Livewire::component('offer-staff-page',OfferStaffPage::class);
// Off-page warnings are counted cheaply; formula explanations cover only the current page.
$last=Tld::where('server_id',$binding->serverId)->where('tld','like','scale%')->orderByDesc('tld')->take(2)->get();
DB::table('domains_tlds')->where('id',$last[0]->id)->update(['catalogue_intent'=>'{"sell":']);
DB::table('domains_tlds')->where('id',$last[1]->id)->update(['catalogue_intent'=>\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::json(['sell'=>true,'pricing_rule'=>['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'42.00','renew'=>'43.00','transfer'=>'44.00']]])]);
$component=\Livewire\Livewire::actingAs($actor)->test(OfferStaffPage::class)->assertSee('12.00');
same(599,$component->instance()->getTableRecords()->total(),'Real rendered table preserves paginator');
$component->assertSee('Prices left out on other pages')->assertSee('1 more TLD has prices left out.');
same([],array_column($component->instance()->savedPrices()['skipped'],'id'),'Saved prices remain limited to the current page');
expect(!collect($component->instance()->explanations())->contains(fn($e)=>in_array('.'.$last[1]->tld,$e['tlds'],true)),'Off-page formulas wait for their own page');
$component->searchTable('com')->assertSee('12.00');same(1,$component->instance()->getTableRecords()->total(),'Real rendered search narrows converted identities');
$component->assertDontSee('Prices left out');same([],array_column($component->instance()->savedPrices()['skipped'],'id'),'Search excludes the unrelated warning');
expect(collect($component->instance()->explanations())->every(fn($e)=>$e['tlds']===['.com']),'Formula explanations follow the filtered list');
// Apply each destination filter state in one real request; intermediate states have no assertions.
$component->set(['tableSearch'=>'','tableFilters.no_cost'=>['isActive'=>true]]);same(598,$component->instance()->getTableRecords()->total(),'No-cost filter uses normalized observation');
$component->set(['tableFilters.no_cost'=>['isActive'=>false],'tableFilters.country'=>['isActive'=>true]]);same(0,$component->instance()->getTableRecords()->total(),'Country filter stays a query filter');
// Installed TLD-page failures are covered by offer-installed-staff-test on persisted bridge state.
// Mixed valid/missing batch results must reach the existing page failure notification.
$staffWrites=new class($native,$sellers,$bindings) extends \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites {public StaffCommands $commands;protected function catalogue():?StaffCommands{return $this->commands;}};$staffWrites->commands=$commands;app()->instance(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class,$staffWrites);
session()->forget('filament.notifications');
$m=new ReflectionMethod(\Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::class,'write');
// Reuse the mounted page: the guarded write is independent of the table's active filters.
$editable=$component->instance();$m->invoke($editable,['choices'=>['com'=>'skip','missing'=>'skip']]);
expect(collect(session('filament.notifications',[]))->contains(fn($n)=>$n['title']==="Couldn't save"&&str_contains($n['body'],'.missing')),'Page must report the missing row as well as saving its valid sibling');
same(false,\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->fresh()->catalogue_intent)['sell'],'Valid sibling remains saved when reporting missing row');
$actor->role()->first()->update(['permissions'=>[Staff::VIEW]]);$redacted=$source->page(collect([$t->fresh()]));
foreach($redacted[$t->id]['prices'] as $price){expect(!array_key_exists('cost',$price),'Revoked cost permission redacts snapshot');}
$plain=new SellTable([$binding->serverId=>$binding],1,false,static fn()=>[],false,$source);
same(null,$plain->rows('com')->first()->register_cost,'SQL presentation rows omit costs without permission');
\Livewire\Livewire::actingAs($actor)->test(OfferStaffPage::class)->assertDontSee('USD 6.00')->assertActionHidden('csv');
try{$list->csv();expect(false,'CSV requires current costs permission');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'CSV permission is fresh');}
echo 'Staff page queries: '.$count."\n";
$nativeSuccessful=true;echo "Offer staff reads: ok\n";
