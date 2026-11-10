<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Cost,Facts,FactsObserved,FeedObserved,OfferState,Query,StaffCatalogue};
use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
Auth::setUser(staffUser(array_keys(Staff::permissions())));$t=$tlds[0]->fresh();
$clock->instant=$now=$now->modify('+1 minute');pinStaffClock($now);
$q=new Query($binding,['com'],'register');$rev=static fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
$cost=static fn(string $action,int $term,string $amount,CostUnit $unit=CostUnit::TermTotal)=>new Cost($action,$term,true,$amount,$unit,'synthetic',$now,$now->modify('+1 day'));
$ports->apply(new FeedObserved($binding,'com',[$cost('register',1,'15.5500'),$cost('renew',1,'8.9500'),$cost('register',2,'8.9500',CostUnit::Annual),$cost('register',5,'8.9500',CostUnit::Annual)]),$rev());
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,2,5],'renew'=>[1]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$rev());
$source=new StaffCatalogue($ports,$clock);$rule=PricingRule::validate(['mode'=>'fixed','round_up'=>'none','fixed'=>['register'=>'9','renew'=>'9','transfer'=>'9']]);
foreach([2=>'24.5000',5=>'51.3500'] as $years=>$expected){
    $preview=collect($source->preview($t->fresh(),$rule))->first(fn($p)=>$p['action']==='register'&&$p['term']===$years);
    same([$expected,'below_cost'],[$preview['cost'],$preview['hold']],'Preview uses built cost and marks the loss for '.$years.' years');
    $page=collect($source->page(collect([$t->fresh()]))[$t->id]['prices'])->first(fn($p)=>$p['action']==='register'&&$p['term']===$years);
    same(Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::money($expected,4),$page['cost'],'TLD page uses built cost for '.$years.' years');
    $matrix=$source->matrix(collect([$t->fresh()]),$years)['rows'][0]['prices']['register'];
    same($expected,$matrix['cost'],'Price matrix uses built cost for '.$years.' years');
}
$defaults=DB::table('domains_accounts')->where('server_id',$binding->serverId)->value('catalogue_defaults');
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>OfferState::json(['defaultLongest'=>11])]);
same([],$source->page(collect([$t->fresh()]))[$t->id]['offers'],'Unreadable account defaults leave no offers');
foreach([2=>'24.5000',5=>'51.3500'] as $years=>$expected){
    $row=$source->matrix(collect([$t->fresh()]),$years)['rows'][0]['prices']['register'];
    same([$expected,null],[$row['cost'],$row['price']],'Unreadable-default matrix keeps the built cost and closes sales');
}
DB::table('domains_accounts')->where('server_id',$binding->serverId)->update(['catalogue_defaults'=>$defaults]);
same(0,$reads->calls,'Staff pricing reads make no provider calls');
$nativeSuccessful=true;echo "Staff multi-year cost readers agree.\n";
