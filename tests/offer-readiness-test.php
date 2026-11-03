<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real account/offer/native rows exercise mixed per-row readiness without provider requests.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities, OfferReadiness, OfferReader, OfferState};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Cost, Edit, Facts, FactsObserved, FeedObserved, Intent, Query};
use Paymenter\Extensions\Servers\Domains\Models\Account;
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/OfferReadiness.php'),'Shared offer readiness projection required');
require __DIR__.'/Support/offer-commerce.php';
extract(offerCommerceFixture());
$caps=new Capabilities($ports,$clock,static fn()=>null);
$ready=new OfferReadiness(new OfferReader($clock,static fn()=>null,static fn()=>true),$caps,$clock);
$a=Account::where('server_id',$binding->serverId)->sole();
$features=['registration'=>true,'renewal'=>false,'transfer'=>false];
$r=$ready->review($a,$features);
expect($r['costs']!==[]&&$r['missing']===null,'Current selected offers are ready');
$q=new Query($binding,['net'],'register');$revision=fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
$costs=[];
foreach(['register','renew','transfer'] as $action){$costs[]=new Cost($action,1,true,'8.0000',\Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,'synthetic',$now,$now->modify('+1 day'));}
$ports->apply(new FeedObserved($binding,'net',$costs),$revision());
$ports->apply(new FactsObserved($binding,'net',new Facts(['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$revision());
$ports->apply(Edit::override($binding,'net',new Intent(sell:true)),$revision());
$ports->apply(Edit::seller($binding,'net',['register']),$revision());
$stamp=$ready->stamp($a);
DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['catalogue_facts'=>null]);
$r=$ready->review($a,$features);
expect($r['failures']!==[]&&$r['missing']!==null,'Unknown facts refuse Activate and name the failed row');
expect(collect($r['costs'])->contains('tld','net'),'A failed ending retains readable sibling readiness');
expect($stamp!==$ready->stamp($a),'Independent fact changes stale the review stamp');
same($r,DB::transaction(fn()=>$ready->review($a,$features,true)),'Display and strict locked readiness use the same offer decisions');
foreach(['null','{"stops":"register"}'] as $malformed){
    DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['catalogue_intent'=>$malformed]);
    $display=$ready->review($a,$features);
    expect(collect($display['failures'])->contains('tld','com')&&$display['missing']!==null,'Malformed intent names its unavailable ending');
    expect(collect($display['costs'])->contains('tld','net'),'Malformed intent retains readable sibling readiness');
    same($display,DB::transaction(fn()=>$ready->review($a,$features,true)),'Malformed intent is isolated in strict readiness too');
}
same(0,$reads->calls,'Readiness never resolves a provider');
$nativeSuccessful=true;
echo "Offer readiness: ok\n";
