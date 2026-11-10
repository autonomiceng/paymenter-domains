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
    // Each case starts from transfers off, so its own save or refusal is proved.
    $setFacts($facts);$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>false]]]]);
    $before=json_decode(DB::table('domains_tlds')->where('id',$tlds[0]->id)->value('catalogue_intent'),true);same(false,$before['transfers']??null,$case.' starts with transfers off');$offHint=$hint();
    $setFacts($json);
    if($case==='unsupported'){
        // Widening against current unsupported terms is refused outright and saves nothing.
        $refused=false;try{$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>true]]]]);}catch(DomainException){$refused=true;}
        expect($refused,$case.' registrar terms refuse Transfers On');
        same($before,json_decode(DB::table('domains_tlds')->where('id',$tlds[0]->id)->value('catalogue_intent'),true),$case.' registrar terms keep the saved intent');
    }else{
        // Unknown terms save the pending intent; current terms decide the seller row later.
        $run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>true]]]]);
        same(true,json_decode(DB::table('domains_tlds')->where('id',$tlds[0]->id)->value('catalogue_intent'),true)['transfers']??null,$case.' registrar terms save the pending transfer intent');
    }
    same(0,(int)$policy()->transfer_enabled,$case.' registrar terms leave the seller row transfer flag off');
    $setFacts($facts);
    same($case==='unsupported'?$offHint:'Allow transfers in More → Change registrar on .com.',$hint(),$case.' terms: the staff hint names the real next step');
}
// Transfer intent is checked against current terms even while the seller row has transfers off.
$intentOf=static fn()=>json_decode(DB::table('domains_tlds')->where('id',$tlds[0]->id)->value('catalogue_intent'),true);
$transferIntent=static function()use($writer,$review,$binding,$revision,$intentOf):void{
    $raw=$intentOf();$intent=new Paymenter\Extensions\Servers\Domains\Catalogue\Intent(...[...array_intersect_key($raw,get_object_vars(new Paymenter\Extensions\Servers\Domains\Catalogue\Intent)),'transfers'=>true]);
    $writer->reviewed($review('acceptance'),[[Edit::override($binding,'com',$intent),$revision()]],static fn()=>StaffCommands::stamp($binding->serverId,null));
};
$unsupported=str_replace('"transfer":[1]','"transfer":[]',$facts);
// Start again from transfers off.
$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>false]]]]);
same([false,0],[$intentOf()['transfers']??null,(int)$policy()->transfer_enabled],'Transfers start off');
$setFacts($unsupported);$before=$intentOf();
try{$transferIntent();$refusal=null;}catch(DomainException $e){$refusal=$e->getMessage();}
same('Explicit verified widening required.',$refusal,'Transfer intent against unsupported terms is refused with the seller row off');
same($before,$intentOf(),'The refused transfer intent is not saved');
// Intent saved while terms are unknown is checked again when Change registrar later allows transfers.
$setFacts(null);$transferIntent();
same([true,0],[$intentOf()['transfers']??null,(int)$policy()->transfer_enabled],'Unknown terms keep the transfer intent with the seller row off');
$setFacts($unsupported);
try{$run('seller',['seller'=>$binding->serverId,'register'=>true,'transfer'=>true],$tlds[0]->id);$refusal=null;}catch(DomainException $e){$refusal=$e->getMessage();}
same('Explicit verified widening required.',$refusal,'Allowing transfers on the registrar is checked against current unsupported terms');
same([1,0],[(int)$policy()->register_enabled,(int)$policy()->transfer_enabled],'The refused registrar change keeps transfers off');
commerceReject(fn()=>$storefront->offer('com','transfer',1));
// With current terms that list transfers, the same registrar change is accepted.
$setFacts($facts);
$run('seller',['seller'=>$binding->serverId,'register'=>true,'transfer'=>true],$tlds[0]->id);
same([1,1],[(int)$policy()->register_enabled,(int)$policy()->transfer_enabled],'Supported terms let the registrar allow transfers');
$run('acceptance',['sell'=>['terms'=>['com'=>['transfer'=>false]]]]);
same(0,(int)$policy()->transfer_enabled,'Transfers Off resets the seller row for the next steps');
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
