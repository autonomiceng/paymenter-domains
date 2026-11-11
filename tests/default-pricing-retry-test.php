<?php
// Native observations, publication revisions and retained native prices require the real writer and database.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Projection,Writer};
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CardFee,PricingRule,StaffSettingsReview as Review};
extract(commerceFixture(registrars:['enom']));tldPanel();$target=$tlds[0];
$sibling=$target->replicate();$sibling->tld='net';$sibling->save();
foreach(Price::where('server_id',$target->server_id)->where('tld',$target->tld)->get() as $price){$copy=$price->replicate();$copy->tld='net';$copy->save();}
$policy=\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld',$target->tld)->sole()->replicate();$policy->tld='net';
\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::withPolicyWrite(static fn()=>$policy->save());
$actor=staffUser([...array_keys(Staff::permissions()),...array_map(Review::permission(...),array_keys(Review::ACTIONS))]);Auth::setUser($actor);
$writer=new Writer(new Projection($native),$clock,static fn()=>null,static fn()=>true);
$rule=PricingRule::validate(['mode'=>'markup_percent','markup'=>'40','round_up'=>'none']);
$save=static fn()=>$writer->defaultPricing(pricingReview($actor->id),$rule,CardFee::NONE);
$priceRows=static fn(Tld $t)=>DB::table('domains_prices')->where('server_id',$t->server_id)->where('tld',$t->tld)->orderBy('id')->get()->toJson();
$state=static fn(Tld $t)=>[$priceRows($t),$t->fresh()->catalogue_revision,$t->fresh()->catalogue_published_revision];
$missing=Price::where('server_id',$target->server_id)->where('tld',$target->tld)->where('action','renew')->sole();
$cost=costEvidence($missing);DB::table('domains_prices')->where('id',$missing->id)->update([...costColumns(null)]);
$before=$priceRows($target);
same(['published'=>1,'failed'=>['Synthetic #'.$target->server_id=>['.com']],'skipped'=>[]],$save(),'A missing observation names the failed target while its sibling publishes');
same($before,$priceRows($target),'A refused target retains every price byte and revision');
DB::table('domains_prices')->where('id',$missing->id)->update([...costColumns($cost)]);$siblingBefore=$state($sibling);
same(['published'=>1,'failed'=>[],'skipped'=>[]],$retry=$save(),'Saving the same default retries only its failed publication: '.json_encode($retry));
same($siblingBefore,$state($sibling),'The sibling already on the default retains exact prices and publication revisions');
same('8.40',$prices->current($target->server_id,$target->account_fingerprint,'com','register',1,'USD',$now)->retail,'The repaired target uses the saved default');
$before=$state($target);
same(['published'=>0,'failed'=>[],'skipped'=>[]],$save(),'A fully caught-up repeated save publishes nothing');
same($before,$state($target),'A caught-up repeated save preserves the target exactly');same($siblingBefore,$state($sibling),'A repeated save preserves its sibling exactly');
// Local default and seller revisions can move without changing the offer component.
foreach(['defaults','selection'] as $component){
    if($component==='defaults'){
        $account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$target->server_id)->sole();
        $defaults=\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($account->catalogue_defaults);$defaults['_revision']=($defaults['_revision']??0)+1;
        DB::table('domains_accounts')->where('server_id',$target->server_id)->update(['catalogue_defaults'=>json_encode($defaults,JSON_THROW_ON_ERROR)]);
    }else{DB::table('domains_sales_policies')->where('tld',$target->tld)->increment('revision');}
    $before=$state($target);
    same(['published'=>$component==='defaults'?2:1,'failed'=>[],'skipped'=>[]],$save(),'The same pricing retries a stale '.$component.' publication revision');
    expect($before[2]!==$target->fresh()->catalogue_published_revision,'The stale '.$component.' marker is replaced');
    same(['published'=>0,'failed'=>[],'skipped'=>[]],$save(),'The repaired '.$component.' revision is current on the next save');
}
same(0,$reads->calls,'Publication retries use only local evidence');
$nativeSuccessful=true;echo "Default pricing retries missed publications once without republishing current siblings.\n";
