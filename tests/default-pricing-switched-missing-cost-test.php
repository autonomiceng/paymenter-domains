<?php
// Native retrieval interleaving and exact retained rows need the real writer and database.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState,Pricing,Projection,Writer};
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PricingRule,PricingRules,StaffSettingsReview as Review};
extract(commerceFixture(registrars:['enom']));tldPanel();$target=$tlds[0];
// Two endings on one real account preserve the sibling witness without unrelated enrollment setup.
$sibling=$target->replicate();$sibling->tld='net';$sibling->save();
foreach(Price::where('server_id',$target->server_id)->where('tld',$target->tld)->get() as $price){$copy=$price->replicate();$copy->tld='net';$copy->save();}
$policy=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$target->tld)->sole()->replicate();$policy->tld='net';
\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::withPolicyWrite(static fn()=>$policy->save());
$actor=staffUser([...array_keys(Staff::permissions()),...array_map(Review::permission(...),array_keys(Review::ACTIONS))]);Auth::setUser($actor);
$own=PricingRule::validate(['mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed'=>['register'=>'20.00','renew'=>'21.00','transfer'=>'22.00']]);
$rawPrices=static fn()=>DB::table('domains_prices')->where('server_id',$target->server_id)->where('tld',$target->tld)->orderBy('id')->get()->toJson();
DB::table('domains_prices')->where('server_id',$target->server_id)->where('tld',$target->tld)->update(['catalogue_observation'=>null]);
$before=$rawPrices();$armed=true;
Tld::retrieved(static function(Tld $t)use(&$armed,$target,$own):void {
    if(!$armed||$t->id!==$target->id||DB::transactionLevel()!==0||(new PricingRules)->defaultRule()['markup']!=='40.00'){return;}
    $armed=false;$intent=OfferState::decode($t->catalogue_intent);$intent['pricing_rule']=$own;
    DB::table('domains_tlds')->where('id',$t->id)->update(['catalogue_intent'=>OfferState::json($intent)]);
});
$result=(new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true))->defaultPricing(
    pricingReview($actor->id),PricingRule::validate(['mode'=>'markup_percent','markup'=>'40','round_up'=>'none']),CardFee::NONE);
expect(!$armed,'The own-rule switch occurs after the shared default commits');
same(['published'=>1,'failed'=>[],'skipped'=>[]],$result,'Only the sibling ending still following the default is published');
same($own,Pricing::own($target->fresh()),'The independently saved custom rule remains exact');
same($before,$rawPrices(),'The switched target retains every price byte and revision');
$nativeSuccessful=true;echo "Default price fanout leaves a target that switched to custom pricing alone even with missing costs.\n";
