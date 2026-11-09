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
same(['published'=>2,'failed'=>[],'skipped'=>[]],$save(),'Start with current publications');
foreach(['null','true','1','"invalid"','{broken','{}','{"offer":1}','{"retailRule":'.\Paymenter\Extensions\Servers\Domains\Catalogue\Retail::VERSION.'}'] as $marker){
    DB::table('domains_tlds')->where('id',$target->id)->update(['catalogue_published_revision'=>$marker]);
    $siblingBefore=$state($sibling);
    // A single-TLD read takes the bad marker as a publication still to come, as the batch read does.
    $single=$ports->readOffers(new \Paymenter\Extensions\Servers\Domains\Catalogue\Query(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$target->server_id)->sole()),[$target->tld],'register'),\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0];
    same([false,'publication_pending'],[$single->available,$single->reason],'A single-TLD read reads a bad marker as publication pending: '.$marker);
    same(['published'=>1,'failed'=>[],'skipped'=>[]],$save(),'An invalid retained marker is repaired by the repeated pricing save: '.$marker);
    $t=$target->fresh();$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole();
    $policy=DB::table('domains_sales_policies')->where('tld',$t->tld)->first();
    same(get_object_vars(\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::revision($t,\Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($account->catalogue_defaults),$policy)),
        \Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::decode($t->catalogue_published_revision),'The repaired marker contains the complete current revision');
    same($siblingBefore,$state($sibling),'Repair leaves the healthy sibling unchanged');
    same('8.40',$prices->current($target->server_id,$target->account_fingerprint,'com','register',1,'USD',$now)->retail,'Repair publishes the exact saved default');
}
// A marker that names only the current retail version is incomplete. Repair still fixes a paused TLD's kept native
// price before the marker is replaced; a repeated save then has nothing to do and issued invoices stay as they were.
$nativePrice=static fn()=>$native->product($target,'register')->plans()->where('billing_period',1)->sole()->prices()->sole();
$invoices=static fn()=>[DB::table('invoices')->orderBy('id')->get()->toJson(),DB::table('invoice_items')->orderBy('id')->get()->toJson()];
$invoicesBefore=$invoices();
$intent=json_decode($target->fresh()->catalogue_intent,true);
DB::table('domains_tlds')->where('id',$target->id)->update(['catalogue_intent'=>json_encode([...$intent,'salesPaused'=>true]),
    'catalogue_published_revision'=>json_encode(['retailRule'=>\Paymenter\Extensions\Servers\Domains\Catalogue\Retail::VERSION])]);
DB::table('prices')->where('id',$nativePrice()->id)->update(['price'=>'0.01']);
same(['published'=>1,'failed'=>[],'skipped'=>[]],$save(),'An incomplete current-version marker on a paused TLD is repaired');
same(840,PricingRule::minor((string)$nativePrice()->getRawOriginal('price')),'Repair fixes the paused TLD kept native price');
expect(isset(json_decode($target->fresh()->catalogue_published_revision,true)['offer']),'The repaired paused marker is complete');
$repaired=$state($target);
same(['published'=>0,'failed'=>[],'skipped'=>[]],$save(),'A repeated save leaves the repaired paused TLD alone');
same($repaired,$state($target),'Repeated repair changes nothing');
same(840,PricingRule::minor((string)$nativePrice()->getRawOriginal('price')),'Repeated repair keeps the native price');
same(true,json_decode($target->fresh()->catalogue_intent,true)['salesPaused']??null,'Repair keeps sales paused');
same($invoicesBefore,$invoices(),'Repair leaves invoices unchanged');
same(0,$reads->calls,'Marker repair uses only local evidence');
$nativeSuccessful=true;echo "Default pricing repairs invalid retained publication markers.\n";
