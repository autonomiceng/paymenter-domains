<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-tlds.php';
use App\Models\Server;
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{SellTable,Staff,StaffGrants,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{StaffCatalogue,StaffCommands,Writer,Projection};
use Paymenter\Extensions\Servers\Domains\Models\{Account,SellRow,Tld};
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
DB::table('domains_prices')->where('server_id',$binding->serverId)->update(['wholesale'=>'999.0000','eligibility'=>null]);
same('6.0000',$source->matrix(collect([$t->fresh()]),1)['rows'][0]['prices']['register']['cost'],'Normalized observation supplies cost, not obsolete columns');
$seed=$t->fresh()->getRawOriginal();unset($seed['id']);$batch=[];
for($i=0;$i<598;$i++){$batch[]=[...$seed,'tld'=>'scale'.chr(97+intdiv($i,26)).chr(97+$i%26)];}
foreach(array_chunk($batch,100) as $chunk){DB::table('domains_tlds')->insert($chunk);}
$before=SellRow::count();$writes=0;DB::listen(static function($q)use(&$writes):void {if(preg_match('/^(insert|update|delete)/i',$q->sql)){$writes++;}});
$page=[];$table=new SellTable([$binding->serverId=>$binding],1,true,static function()use(&$page){return $page;},false,$source);
DB::enableQueryLog();DB::flushQueryLog();
$pager=$table->rows(null)->orderBy('tld')->paginate(25);$page=$pager->items();
foreach($page as $row){$table->status($row);$table->terms($row);$table->cell($row,'register','price');}
$count=count(DB::getQueryLog());DB::disableQueryLog();
same(599,$pager->total(),'Converted identities paginate without shadow sales rows');expect($count<100,'Bounded 25-row Staff page, got '.$count.' queries');
same($before,SellRow::count(),'Staff reads never create SellRows');same(0,$writes,'Staff table/price reads have no writes');
same(1,$table->rows('com')->count(),'Focused ending remains a query filter');same(598,$table->rows(null)->where('tld','like','scale%')->count(),'Search remains a query filter');
// Render the real Filament table against the converted query, including search, filters and paging.
$GLOBALS['staffSource']=$source;
class OfferStaffPage extends \Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell
{protected function catalogue():?StaffCatalogue{return $GLOBALS['staffSource'];}
// Blade calls binding() on the concrete component; expose the inherited private helper in this fixture only.
public function binding():?\Paymenter\Extensions\Servers\Domains\Data\AccountBinding{return $this->account?\Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(Account::where('server_id',$this->account)->sole()):null;}}
\Livewire\Livewire::component('offer-staff-page',OfferStaffPage::class);
$component=\Livewire\Livewire::actingAs($actor)->test(OfferStaffPage::class)->assertSee('12.00');
same(599,$component->instance()->getTableRecords()->total(),'Real rendered table preserves paginator');
$component->searchTable('com')->assertSee('12.00');same(1,$component->instance()->getTableRecords()->total(),'Real rendered search narrows converted identities');
$component->searchTable('')->filterTable('no_cost');same(598,$component->instance()->getTableRecords()->total(),'No-cost filter uses normalized observation');
$component->removeTableFilter('no_cost')->filterTable('country');same(0,$component->instance()->getTableRecords()->total(),'Country filter stays a query filter');
$actor->role()->first()->update(['permissions'=>[Staff::VIEW]]);$redacted=$source->page(collect([$t->fresh()]));
foreach($redacted[$t->id]['prices'] as $price){expect(!array_key_exists('cost',$price),'Revoked cost permission redacts snapshot');}
$plain=new SellTable([$binding->serverId=>$binding],1,false,static fn()=>[],false,$source);
same(null,$plain->rows('com')->first()->register_cost,'SQL presentation rows omit costs without permission');
\Livewire\Livewire::actingAs($actor)->test(OfferStaffPage::class)->assertDontSee('USD 6.00')->assertActionHidden('csv');
try{$list->csv();expect(false,'CSV requires current costs permission');}catch(Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),'CSV permission is fresh');}
echo 'Staff page queries: '.$count."\n";
$nativeSuccessful=true;echo "Offer staff reads: ok\n";
