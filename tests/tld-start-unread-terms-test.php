<?php
// Native page regression: Start selling sets up a TLD the account doesn't sell by default, even while its registrar
// terms are out of date. While set to sell, the head says so and Stop selling shows. When fresh terms rule a sale out,
// Start selling is not offered and the head names the block.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Edit,Facts,Intent,OfferState,Query,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,StaffSettingsWrites};
extract(commerceFixture(true));staffSalesFixture();tldPanel();pinStaffClock($now);$ports=CallerPorts::routed();
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings));
$t=$tlds[0];$account=Account::where('server_id',$t->server_id)->sole();$binding=AccountBindings::binding($account);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$offer=static fn(string $action)=>$ports->readOffers(new Query($binding,['com'],$action),Context::Staff)[0];
$said=static fn($page)=>trim(preg_replace('/\s+/',' ',html_entity_decode(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~si','',$page->html())),ENT_QUOTES)));
// The account sells only TLDs set to Sell, and .com has no choice of its own (the demo's .abc).
DB::table('domains_accounts')->where('id',$account->id)->update(['catalogue_defaults'=>OfferState::json([...OfferState::decode($account->catalogue_defaults),'defaultSell'=>false])]);
// Its registrar terms were read once and are out of date now.
$facts=static fn(array $register,int $age,bool $nameservers=false)=>DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::encodeFacts(new Facts(['register'=>$register,'renew'=>[1],'transfer'=>[1]],[],true,$nameservers,false,'synthetic',$now->modify('-'.($age+1).' days'),$now->modify(($age>0?'-':'+').max($age,1).' day')))]);
$facts([1,3],2);
$ports->apply(Edit::override($binding,'com',new Intent(stops:[])),$offer('register')->revision);
same(null,OfferState::decode($t->fresh()->catalogue_intent)['sell']??null,'.com has no sell choice of its own');
same(false,OfferState::facts($t->fresh()->catalogue_facts)->fresh($now),'.com terms are out of date');
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionVisible('start_selling');
// Not set to sell while its terms wait: the words promise no sale.
same('Not sold by default',TldFacts::salesState($t->fresh())['reason'],'The TLD list says .com is not sold by default');
expect(str_contains($said($page),'Not sold by default'),'The page says .com is not sold by default: '.$said($page));
same('primary',$page->instance()->startSellingAction()->getColor(),'Start selling is the primary button');
$page->mountAction('start_selling');$confirm=$page->instance()->getMountedAction()->getModalDescription();
$page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Change recorded');
expect(str_contains($confirm,'registrar terms'),'The confirm says sales wait for the registrar terms: '.$confirm);
$current=$t->fresh();
same(true,OfferState::decode($current->catalogue_intent)['sell']??null,'Start selling sets .com to Sell');
same(true,StaffCatalogue::routed()->setting($current,'enabled'),'.com is on');
// Set to sell while its terms wait: the words say so without promising a sale, and Stop selling is the one sales action.
$page->assertActionHidden('start_selling')->assertActionVisible('stop_selling');
same('Set to sell; checking registrar terms',TldFacts::salesState($current)['reason'],'The TLD list says .com is set to sell while its terms are checked');
expect(str_contains($said($page),'Set to sell; checking registrar terms'),'The page says .com is set to sell while its terms are checked: '.$said($page));
// Fresh terms need nameservers at registration, which checkout can't send. It stays set to sell, so Stop selling stays.
$facts([1,3],0,true);
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('start_selling')->assertActionVisible('stop_selling');
same(['label'=>'Not for sale','tone'=>'warning','reason'=>'Set to sell, but this registrar needs nameservers at registration'],TldFacts::salesState($t->fresh()),'The TLD list names the requirement .com fails, with the amber mark');
expect(str_contains($said($page),'Set to sell, but this registrar needs nameservers at registration'),'The page names the requirement: '.$said($page));
same('Use Change registrar to sell it through another account.',TldFacts::fixText(TldFacts::salesState($t->fresh())['reason'],true),'The list Note says what to do');
same(TldFacts::ASK_SELLER,TldFacts::fixText(TldFacts::salesState($t->fresh())['reason'],false),'Staff who cannot change the registrar are told who can');
$change=collect($page->instance()->getCachedHeaderActions())->first(fn($a)=>$a instanceof \Filament\Actions\Action&&$a->getName()==='seller');
same(['Change registrar','primary'],[$change?->getLabel(),$change?->getColor()],'Change registrar is the one action for a requirement this registrar fails');
// Change registrar names that requirement on this account's choice, and a refused save says it instead of asking for a retry.
$needs='This registrar needs nameservers at registration for .com. Choose another registrar.';
same(['reason'=>$needs,'disabled'=>true],array_intersect_key(Paymenter\Extensions\Servers\Domains\Admin\Support\TldRegistrar::choices($t->id)[$t->server_id],['reason'=>1,'disabled'=>1]),'The registrar choice names the requirement and is not offered');
same($needs.' Nothing was saved.',Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::refusal(new DomainException('Explicit verified widening required.'),'seller',['seller'=>$t->server_id],$t->server_id,$t->id),'The refused registrar change names the requirement');
// Stopped by staff with the same terms: Start selling can't lift the block, and the words drop "Set to sell".
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,stops:['register','transfer'])),$offer('register')->revision);
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('start_selling')->assertActionHidden('stop_selling');
same('This registrar needs nameservers at registration',TldFacts::salesState($t->fresh())['reason'],'A stopped .com names the block without Set to sell');
// Fresh terms take only 3-year registrations, longer than .com's 1 year, so they rule the sale out. Start selling is not offered; the head names the block
// and its fix holds the primary spot.
$ports->apply(Edit::override($binding,'com',new Intent(longest:1,stops:[])),$offer('register')->revision);
$facts([3],0);
$page=Livewire::actingAs($actor)->test(ViewTld::class,['record'=>$t->id])->assertActionHidden('start_selling');
same('Not offered by this registrar',TldFacts::salesState($t->fresh())['reason'],'The TLD list names the registrar block');
expect(str_contains($said($page),'Not offered by this registrar'),'The page names the registrar block: '.$said($page));
$change=collect($page->instance()->getCachedHeaderActions())->first(fn($a)=>$a instanceof \Filament\Actions\Action&&$a->getName()==='seller');
same(['Change registrar','primary'],[$change?->getLabel(),$change?->getColor()],'Change registrar is the primary button');
echo "TLD start with unread terms passed.\n";$nativeSuccessful=true;
