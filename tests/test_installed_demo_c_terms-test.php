<?php
// Native panel boundary; read-only Composer dependencies. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,StaffCatalogue,Capabilities,OfferState};
use Paymenter\Extensions\Servers\Domains\Models\OfferRow;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$defaults=OfferState::decode(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$binding->serverId)->sole()->catalogue_defaults);
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json([...$defaults,'defaultTransfers'=>true])]);
$t=$tlds[0]->fresh();$intent=OfferState::decode($t->catalogue_intent);unset($intent['transfers']);
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
DB::table('domains_sales_policies')->where('tld',$t->tld)->update(['transfer_enabled'=>false]);
$probe=new ReflectionProperty(CallerRouting::class,'probe');$probe->setValue(null,[StaffCatalogue::class=>new StaffCatalogue($ports,$clock),Capabilities::class=>new Capabilities($ports,$clock,static fn()=>null)]);
$page=new WhatYouSell;$edit=new ReflectionMethod($page,'editFields');
// Real R8 path: default transfers On, no intent override, native seller policy Off.
$row=(new StaffCatalogue($ports,$clock))->rows([$binding],$t->tld)->sole();
same(null,$row->transfer,'The real query has no transfer intent override');
$fields=$edit->invoke($page,$row);$transfer=collect($fields)->first(fn($f)=>method_exists($f,'getName')&&$f->getName()==='transfer');
same('Leave as is (Off)',$transfer->getOptions()['keep'],'Default pricing does not replace saved transfer Off');
$rows=Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::termReadiness($binding,'com');
// Missing current approval keeps Ordinary annual until fresh facts; Grace retains its separate review line.
same(['Early renewal','1 year','Current terms are not saved yet. Review the registrar terms. Where the registrar supports it, the scheduled check fills them.'],$rows[1],
    'Ordinary retains annual renewal and explains missing current approval');
same(['Grace renewal','Off','Review registry rules and safe deadlines before enabling grace renewal.'],$rows[3],'Grace keeps its review explanation');
same(1,substr_count(view('domains::admin.term-readiness',['readiness'=>$rows])->render(),'safe deadlines'),'The Terms dialog says the grace review once');
// Ordinary and Grace keep separate explanations.
$ordinary=Capabilities::routed()->termReadiness($binding,'com')['families']['ordinary'];
same(['state'=>'annual_only','reason'=>'family_unreviewed','terms'=>[1]],$ordinary,'Ordinary family is unreviewed and annual-only');
same('Longer terms are not reviewed yet.',
    Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::termReason($ordinary['reason']),
    'Unreviewed Ordinary family keeps its own explanation rather than the Grace review');
$accountHtml=Livewire\Livewire::actingAs($actor)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$binding->serverId])->html();
$accountText=storefrontDom($accountHtml)->evaluate('string(//body)');
foreach(['Selected TLD terms','1 of 1 read','Scheduled renewals','1 year'] as $text){
    expect(str_contains($accountText,$text),'Account sheet renders current term readiness: '.$text);
}
expect(!str_contains($accountText,'Supported terms'),'Account sheet does not revive the replaced readiness label');
$r10Failures=[];$r10Check=static function(bool $ok,string $message)use(&$r10Failures):void{if(!$ok){$r10Failures[]=$message;}};
// More than ten unsold alphabetic entries must not hide the sold .com.
for($i=0;$i<11;$i++){
    $unsold=$t->replicate();$unsold->tld='aaa'.chr(ord('a')+$i).'.pro';$unsold->catalogue_facts=null;
    $unsold->catalogue_intent=OfferState::json(['sell'=>false]);$unsold->save();
}
$accountRows=Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::renewals($binding->serverId);
$selected=array_values(array_filter($accountRows,fn($r)=>$r['label']==='Selected TLD terms'));
$r10Check(count($selected)===1&&$selected[0]['value']==='1 of 1 read','Unsold alphabetic catalogue does not change the complete selected TLD summary');
$r10Check(count(array_filter($accountRows,fn($r)=>str_starts_with($r['label'],'.aaa')))===0,'Unsold TLDs do not add account readiness rows');
same('1 to 10 years',Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::termYears(range(1,10)),'Contiguous term range is compact');
$editAction=(new WhatYouSell)->table(Filament\Tables\Table::make(new WhatYouSell))->getBulkActions()[0];
same('3xl',$editAction->getModalWidth(),'Terms dialog has room for compact price and renewal rows');
$opened=Livewire\Livewire::actingAs($actor)->test(WhatYouSell::class);
$key=$opened->instance()->getTableRecordKey($row);
$opened->mountTableBulkAction('edit',[$key])->call('$refresh');
$dialog=$opened->effects['partials']['action-modals.0']??'';
expect(str_contains($dialog,'Grace')&&substr_count($dialog,'Review registry rules and safe deadlines before enabling grace renewal.')===1,'Mounted Terms dialog retains exactly one Grace review explanation');
expect(str_contains($dialog,'Registration')&&str_contains($dialog,'Ordinary')&&str_contains($dialog,'Redemption'),'Opening the actual terms dialog renders both term and renewal rows');

$prices=Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::termPrices($t);
$priceHtml=view('domains::admin.term-prices',compact('prices'))->render();
expect(str_contains($priceHtml,'<dl')&&str_contains($priceHtml,'Registration'),'Term totals use labeled registration rows');
$r10Check(!preg_match('/\$[0-9]+\.[0-9]{3}/',$priceHtml),'Displayed registrar costs have two decimal places');
expect(str_contains($priceHtml,'Customer total')&&str_contains($priceHtml,'Registrar cost')&&str_contains($priceHtml,'3 years'),'Real offer terms include customer total and registrar cost');
// Expired exact-term costs do not erase the retained retail and cost values.
$stalePrices=$prices;
foreach($stalePrices as &$price){$price['synced']=false;}unset($price);
$staleHtml=view('domains::admin.term-prices',['prices'=>$stalePrices])->render();
$r10Check(str_contains($staleHtml,'Customer total $')&&str_contains($staleHtml,'Registrar cost $'),'Outdated terms retain saved customer and registrar totals');
$r10Check(str_contains($staleHtml,'Out of date'),'Outdated registrar cost is labeled');
$gift=new ReflectionMethod(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,'complimentaryRenewal');
$gift=$gift->invoke(null,new Paymenter\Extensions\Servers\Domains\Models\Domain(['status'=>'closed']));
same('Confirm complimentary renewal',$gift->getModalSubmitActionLabel(),'Complimentary confirmation matches the mock');
expect(!$gift->isVisible(),'An ineligible holding has no empty complimentary action');
// A real checkout establishes the service authority; the retained custom annual price is arranged afterward.
$buyer=commerceUser();$cart=commerceCart($buyer);commerceAdd($cart,$native->product($t->fresh(),'register'),placeholderComFixtureName('staff-term-price-placeholder.com'));
commerceCheckout($buyer,$cart)->assertHasNoErrors();
$service=App\Models\Service::where('user_id',$buyer->id)->sole();
DB::table('services')->where('id',$service->id)->update(['price'=>'10.99']);$service->refresh();Auth::setUser($actor);
$holding=new Paymenter\Extensions\Servers\Domains\Models\Domain(['status'=>'closed','domain'=>'example.com','service_id'=>$service->id]);
$gift=(new ReflectionMethod(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,'complimentaryRenewal'))->invoke(null,$holding);
$schema=$gift->getSchema(\Filament\Schemas\Schema::make($page)->statePath('data'))->getComponents(withHidden:true);$text=(string)$schema[array_key_last($schema)]->getContent();
$r10Check(str_contains(storefrontDom($text)->query('//div[dt="Next annual renewal"]/dd')->item(0)?->textContent??'','$10.99 / year'),'Complimentary dialog retains the domain annual price when customer-request eligibility is closed');
// A short preview must not evaluate every explicitly unsold catalogue entry.
$priceQueries=0;DB::listen(static function($query)use(&$priceQueries):void{$priceQueries++;});
Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::renewals($binding->serverId);$smallQueries=$priceQueries;
for($i=0;$i<1100;$i++){$unsold=$t->replicate();$unsold->tld='unsold'.$i.'.example';$unsold->catalogue_facts=null;$unsold->catalogue_intent=OfferState::json(['sell'=>false]);$unsold->save();}
$priceQueries=0;Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::renewals($binding->serverId);
$r10Check($priceQueries<=$smallQueries+4,'Ten-row preview skips offer evaluation for explicitly unsold catalogue entries ('.$smallQueries.' vs '.$priceQueries.' queries)');
// Confirmed annual-only terms and missing facts are different states.
$originalFacts=$t->fresh()->catalogue_facts;$annual=OfferState::decode($originalFacts);$annual['terms']['register']=[1];
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>OfferState::json($annual)]);
$annualRows=Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::renewals($binding->serverId);
$r10Check(collect($annualRows)->contains(fn($r)=>$r['label']==='Selected TLD terms'&&$r['value']==='1 of 1 read'),'Confirmed annual terms count as complete selected TLD readiness');
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>null]);
$missingRows=Paymenter\Extensions\Servers\Domains\Admin\Support\AccountChecklist::renewals($binding->serverId);
$r10Check(collect($missingRows)->contains(fn($r)=>$r['label']==='Unknown term range .aaaa.pro'&&$r['value']==='Missing'),'Missing registrar facts are labeled unknown');
DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_facts'=>$originalFacts]);
$probe->setValue(null,null);
storefrontViews();require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$domain=new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(name:'orbit-example.com',status:'Active',tone:'ok',expiry:'2027-11-20',renewal:'On',renewalPrice:'$14.00',actions:['show'=>'/domains/1'],progress:[],setup:[],transfer:[],management:[],testEnvironment:false,renewNow:['url'=>'/domains/1/renew','terms'=>[1,2]]);
$choices=['lines'=>[1=>['years'=>1,'first_year'=>'14.00','renewal'=>'14.00','total'=>'14.00','expiry'=>'2028-11-20'],2=>['years'=>2,'first_year'=>'14.00','renewal'=>'14.00','total'=>'28.00','expiry'=>'2029-11-20']],'next_annual'=>'14.00'];
$html=view('domains::partials.owner-renew',['domain'=>$domain,'summary'=>null,'support'=>null,'keys'=>['renew'=>str_repeat('a',64)],'renewalChoices'=>$choices])->render();
foreach(['Domain','Expires','New expiry','Total','Next annual renewal','Create invoice','Nov 20, 2028','data-total="$28.00"','for the first added year + $14.00 for the next year.'] as $text){expect(str_contains($html,$text),'Renewal renders '.$text);}
expect(str_contains($html,'2 years: $28.00; new expiry Nov 20, 2029'),'No-script renewal shows every term total and expiry');
expect(strpos($html,'>Cancel</a>')!==false&&strpos($html,'>Create invoice</button>')!==false&&strpos($html,'>Cancel</a>')<strpos($html,'>Create invoice</button>'),'Cancel precedes payment');
expect(strpos($html,'>Cancel</button>')!==false&&strpos($html,'>Create invoice</button>')!==false&&strpos($html,'>Cancel</button>')<strpos($html,'>Create invoice</button>'),'Cancel is a button before payment');
expect(str_contains($html,'domains-total'),'Customer renewal total has shared prominent money treatment');
expect(!str_contains($html,'exact-term')&&!str_contains($html,'registry'),'No staff eligibility reason in customer form');
foreach([null,[],['lines'=>[]]] as $missingChoices){
    try{
        $fallback=view('domains::partials.owner-renew',['domain'=>$domain,'summary'=>null,'support'=>null,'keys'=>['renew'=>str_repeat('a',64)],'renewalChoices'=>$missingChoices])->render();
        preg_match('/<noscript>(.*?)<\/noscript>/s',$fallback,$noScript);
        $r10Check(str_contains($noScript[1]??'','Total and new expiry confirmed at payment.'),'No-script renewal explains missing term totals and expiry');
    }catch(Throwable $error){$r10Check(false,'Missing renewal choices must render safely: '.get_class($error));}
}
$result=storefrontData()['results'][0];$result['term']=1;$result['price']='$12.00';
$html=view('domains::partials.result',['result'=>$result])->render();
$x=storefrontDom($html);same('1 year · $12.00',$x->query('//select/option')->item(0)->textContent,'Years select shows the exact first term total');
expect(str_contains($html,'data-total="$30.00"'),'Search retains each exact total for selection');
$x=storefrontDom($html);same('Years',trim($x->query('//label')->item(0)->textContent),'Search labels Years');
expect(str_contains($html,'>Total<')&&str_contains($html,'>Annual renewal<')&&str_contains($html,'>Add to cart</button>'),'Search total and annual renewal are labeled beside the choice');
$result['terms']=[1=>'$12.00'];$html=view('domains::partials.result',['result'=>$result])->render();
expect(!str_contains($html,'<select'),'Annual-only result has no unsupported years');
$cart=new App\Livewire\Cart;
$unavailable=new ReflectionMethod(Paymenter\Extensions\Servers\Domains\Services\CartCheckoutRefresh::class,'unavailable');
$unavailable->invoke(null,$cart,new Paymenter\Extensions\Servers\Domains\Services\CheckoutRefreshTimeout);
$notice=Paymenter\Extensions\Servers\Domains\Services\CartNotice::get($cart);
same('We could not get your price',$notice['title'],'Quote notice title matches the mock');
same('Try again shortly.',$notice['text'],'Unreadable quote uses the short retry state');
expect(!str_contains($notice['text'],'internal'),'Quote failure does not expose internal detail');
$generic=new App\Livewire\Cart;
$unavailable->invoke(null,$generic,new RuntimeException('synthetic unexpected checkout error'));
$genericNotice=Paymenter\Extensions\Servers\Domains\Services\CartNotice::get($generic);
same([Paymenter\Extensions\Servers\Domains\Services\CartCheckoutRefresh::UNCHECKED_TITLE,'We couldn’t confirm the price and term for staff-term-price-placeholder.com with the registrar, so checkout is paused. Try again in a few minutes, or remove it from your cart.',['staff-term-price-placeholder.com']],
    [$genericNotice['title']??null,$genericNotice['text']??null,$genericNotice['names']??null],'Unexpected checkout failure says why checkout waits, not the quote retry');
expect($r10Failures===[],implode('; ',$r10Failures));
$nativeSuccessful=true;echo "Demo C NS17 forms passed.\n";
