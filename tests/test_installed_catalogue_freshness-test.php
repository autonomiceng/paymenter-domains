<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native projection and the reviewed setting must recover together; a DTO stub cannot prove this.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/offer-commerce.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,OfferState,OfferWork,Query};
use Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,StaffSettingsReview as Review,StaffSettingsWrites};
extract(offerCommerceFixture());tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::setUser($actor);
foreach(DB::table('domains_prices')->where('server_id',$binding->serverId)->get() as $p){
    $cost=OfferState::decode($p->catalogue_observation);$cost['source']='enom_bulk_bucket_'.((int)$p->term===3?2:1);
    $cost['observed_at']=$now->modify('-60 hours')->format(DATE_ATOM);$cost['fresh_until']=$now->modify('-12 hours')->format(DATE_ATOM);
    DB::table('domains_prices')->where('id',$p->id)->update(['catalogue_observation'=>OfferState::json($cost)]);
}
$q=new Query($binding,['com'],'register');
$rev=fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3,transfers:true),[...\Paymenter\Extensions\Servers\Domains\Services\PricingRule::DEFAULT,'mode'=>'fixed','fixed'=>['register'=>'20.00','renew'=>'20.00','transfer'=>'20.00']]),$rev());
$product=$native->product($tlds[0],'register');$plan=$product->plans()->where('billing_period',1)->sole();
$nativeId=$plan->prices()->where('currency_code','USD')->sole()->id;
same('7.80',\Paymenter\Extensions\Servers\Domains\Services\RetailLine::decimal($plan->prices()->whereKey($nativeId)->sole()->price),'Stale publication retains the previous native amount');
$worker=new OfferWork($ports->writer(),$clock,new AccountReadBudget($clock,25),static fn()=>throw new LogicException('Freshness repair must stay local'),static fn()=>0);
$worker->wake($binding);$worker->accountTurn($binding->serverId);
$review=new Review($actor->id,$binding->serverId,null,'price_freshness',Review::ticket($actor->id,$binding->serverId,null,'price_freshness',Review::stamp($binding->serverId,null)),'Synthetic freshness recovery');
(new StaffSettingsWrites($native,$sellers,$bindings))->run($review,['hours'=>72]);
$worker->accountTurn($binding->serverId);
same('$20.00',$storefront->offer('com','register',1)['price'],'Saving longer freshness republishes the recovered native offer');
same($nativeId,$plan->prices()->where('currency_code','USD')->sole()->id,'Freshness recovery retains the native price identity');
same(72,CatalogueFreshness::forAccount($binding->serverId)->hours,'Reviewed account policy remains saved');
$nativeSuccessful=true;
echo "Reviewed freshness republication: ok\n";
