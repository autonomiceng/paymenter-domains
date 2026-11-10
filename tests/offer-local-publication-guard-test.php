<?php
// Native events, rollback and retained queue claims need the installed fixture. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,OfferWork,Projection,UpgradeGate,Writer};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,NativeCatalogue};
extract(commerceFixture());$server=(int)$tlds[0]->server_id;$budget=new AccountReadBudget($clock,48);
require __DIR__.'/Support/runtime-health-publication.php';
$first=$unpublished($tlds[0],'guardaa');$later=$unpublished($tlds[0],'guardab');
$current=true;$tripped=false;$generation=UpgradeGate::generation();
$guard=static function()use(&$current,$generation):void {
    if(!$current||UpgradeGate::generation()!==$generation){throw new DomainException('Synthetic obsolete publication authority.');}
};
$worker=new OfferWork(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true),
    $clock,$budget,static fn()=>$feed,static fn()=>0,generation:$generation,guard:$guard);
App\Models\Product::creating(static function($product)use($first,&$current,&$tripped):void {
    if(!$tripped&&$product->slug===NativeCatalogue::slug($first,'register')){
        $tripped=true;$current=false;throw new RuntimeException('Synthetic failed publication.');
    }
});
$products=DB::table('products')->orderBy('id')->get()->toJson();$prices=DB::table('domains_prices')->orderBy('id')->get()->toJson();
try{$worker->accountTurn($server);expect(false,'Obsolete authority must stop the publication turn');}
catch(DomainException $error){same('Synthetic obsolete publication authority.',$error->getMessage(),'A projection failure cannot swallow the current authority refusal');}
expect($tripped,'The authority change occurs inside a real failed native publication');
same($products,DB::table('products')->orderBy('id')->get()->toJson(),'Failed native publication and later rows write no products');
same($prices,DB::table('domains_prices')->orderBy('id')->get()->toJson(),'Refused turn preserves every observed price byte');
$queue=DB::table('domains_observation_queues')->where('server_id',$server)->sole();
same(0,(int)(OfferState::decode($queue->offer_work)['local_cursor']??0),'Obsolete authority cannot acknowledge a partial scan');
same([null,0],[$queue->offer_claim,(int)$queue->offer_lease],'Refused work releases only its owned claim');
same(null,$native->product($later,'register'),'No later TLD publishes after authority changes');
$current=true;$clock->instant=$clock->now()->modify('+61 seconds');$worker->accountTurn($server);
expect($native->product($first,'register')!==null,'A later authorized turn retries the rolled-back TLD');
expect($native->product($later,'register')!==null,'A later authorized turn publishes the remaining TLD');
// A saved publication marker that can't be read is republished, not a stop for the account's whole scan.
foreach(['{broken','null'] as $marker){
    DB::table('domains_tlds')->where('id',$first->id)->update(['catalogue_published_revision'=>$marker]);
    $clock->instant=$clock->now()->modify('+61 seconds');$worker->accountTurn($server);
    expect(OfferState::currentPublication(DB::table('domains_tlds')->where('id',$first->id)->value('catalogue_published_revision')),'A later turn republishes the TLD with an unreadable marker: '.$marker);
}
$nativeSuccessful=true;echo "Local publication failures preserve account authority and durable recovery\n";
