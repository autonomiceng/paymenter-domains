<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native fixture: What you sell runs the reviewed staff command, and the storefront reads the published offer.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Projection,Query,StaffCatalogue,StaffCommands,Writer};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;
require __DIR__.'/Support/offer-commerce.php';extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
$writer=new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true);
$commands=new StaffCommands($writer,$bindings,static fn()=>null,static fn()=>null);
$review=static fn(string $action,?int $tld=null)=>new Review($actor->id,$binding->serverId,$tld,$action,Review::ticket($actor->id,$binding->serverId,$tld,$action,StaffCommands::stamp($binding->serverId,$tld)),'Synthetic transfer switch review');
$run=static fn(string $action,array $data=[],?int $tld=null)=>$commands->run($review($action,$tld),$data);
$revision=static fn()=>$ports->readOffers(new Query($binding,['com'],'register'),Context::Staff)[0]->revision;
$policy=static fn()=>DB::table('domains_sales_policies')->where('tld','com')->first();
$hint=static function()use($ports,$clock,$binding,$tlds):string{
    $source=new StaffCatalogue($ports,$clock);$t=Tld::findOrFail($tlds[0]->id);$key=$binding->serverId.':com';
    return $source->nextSteps(collect([$t]),[$key=>$source->page(collect([$t]))[$t->id]],'transfer',1)[$key]['next'];
};
// R5 state: Enom sells .com registrations, its seller row has transfers off and What you sell has them off.
$ports->apply(Edit::seller($binding,'com',['register']),$revision());
$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>false]]]]);
commerceReject(fn()=>$storefront->offer('com','transfer',1));
// Without current registrar terms that list transfers, Transfers On leaves the seller row off and the hint names it.
$facts=DB::table('domains_tlds')->where('id',$tlds[0]->id)->value('catalogue_facts');
expect(str_contains($facts,'"transfer":[1]'),'Fixture terms list 1-year transfers');
$setFacts=static fn(?string $json)=>DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['catalogue_facts'=>$json]);
$changed=static function(array $edit)use($facts):string{$f=json_decode($facts,true);return json_encode(array_replace_recursive($f,$edit));};
foreach(['missing'=>null,
    'stale'=>$changed(['observedAt'=>$now->modify('-2 days')->format(DATE_ATOM),'freshUntil'=>$now->modify('-1 second')->format(DATE_ATOM)]),
    'unsupported'=>str_replace('"transfer":[1]','"transfer":[]',$facts)] as $case=>$json){
    $setFacts($json);
    try{$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>true]]]]);}catch(DomainException){/* Widening against current unsupported terms is refused outright. */}
    same(0,(int)$policy()->transfer_enabled,$case.' registrar terms leave the seller row transfer flag off');
    $setFacts($facts);
    same('Allow transfers in More → Change registrar on .com.',$hint(),$case.' terms: the staff hint names the real next step');
}
// Transfers On in What you sell is the one switch: the chosen registrar's seller row now allows them too.
$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>true]]]]);
same([1,1,(int)$binding->serverId],[(int)$policy()->register_enabled,(int)$policy()->transfer_enabled,(int)$policy()->server_id],'Transfers On allows transfers on the seller row and keeps registrations');
expect($storefront->offer('com','transfer',1)!==null,'Transfers On makes a sold transfer purchasable');
same(true,$offers->transfersOn('com'),'Customers see transfers for .com');
// The same explicit switch also updates Change registrar when it is turned off.
$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>false]]]]);
same(0,(int)$policy()->transfer_enabled,'Transfers Off clears the selected registrar transfer flag');
$run('acceptance',['sell'=>['terms'=>['com'=>['longest'=>3]]]]);
same(0,(int)$policy()->transfer_enabled,'An unrelated sales edit does not change seller transfer intent');
$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>true]]]]);
// If the seller row still blocks (changed by hand later), the staff hint names the real switch.
DB::table('domains_sales_policies')->where('tld','com')->update(['transfer_enabled'=>false]);
same('Allow transfers in More → Change registrar on .com.',$hint(),'The staff hint names the real next step');
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>null,'registrar_key'=>null,'account_fingerprint'=>null]);
same('Choose the registrar in More on .com.',$hint(),'With no registrar chosen the hint still asks for one');
same(0,$reads->calls,'Staff switches never read a provider');
$nativeSuccessful=true;
echo "Offer transfer switch: ok\n";
