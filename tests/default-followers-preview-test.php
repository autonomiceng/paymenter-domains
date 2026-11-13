<?php
// Batched default follower saved costs; MIT.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions;
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,PriceCatalogue,PricingRule};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context,Edit,Intent,OfferState,Query,ReadOffer};
extract(commerceFixture(true));Carbon\Carbon::setTestNow($now);$t=$tlds[0];
// Active own/default pricing lives in the typed intent, independently of retained legacy columns.
$other=$tlds[1];$b=AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$other->server_id)->sole());$raw=OfferState::decode($other->catalogue_intent);
$ports->apply(Edit::override($b,$other->tld,new Intent(...array_intersect_key($raw,get_object_vars(new Intent))),PricingRule::DEFAULT),$ports->readOffers(new Query($b,[$other->tld],'register'),Context::Staff)[0]->revision);
$selects=[];$evaluating=false;
DB::listen(static function($query)use(&$selects,&$evaluating):void{
    if($evaluating&&preg_match('/^select\b.*\bfrom\s+["`]domains_prices["`]/i',$query->sql)){$selects[]=$query->sql;}
});
$reach=new ReflectionMethod(SettingsActions::class,'reach');$words=new ReflectionMethod(SettingsActions::class,'reachDefault');
$preview=static function()use($reach,$words,&$selects,&$evaluating):string{
    $selects=[];$evaluating=true;try{return $words->invoke(null,$reach->invoke(null));}finally{$evaluating=false;}
};
same('1 TLD uses Default pricing.',$preview(),'One fresh follower preview');
same(1,count($selects),'One follower uses one Price SELECT');
$base=Price::where('server_id',$t->server_id)->where('tld','com')->orderBy('id')->get();
$seed=static function(string $ending,?callable $change=null)use($t,$base):Tld{
    $next=$t->replicate();$next->tld=$ending;$next->save();
    foreach($base as $price){
        $copy=$price->replicate();$copy->tld=$ending;$cost=OfferState::decode(costEvidence($copy));
        if($change!==null&&!$change($cost)){continue;}
        $copy->forceFill(costColumns(OfferState::json($cost)));$copy->save();
    }
    return $next;
};
$seed('fresh');
$seed('stale',static function(array &$cost)use($now):bool{$cost['observed_at']=$now->modify('-1 day')->format(DATE_ATOM);$cost['fresh_until']=$now->format(DATE_ATOM);return true;});
same('3 TLDs use Default pricing. Costs are missing for .stale. Its prices stay the same until you sync prices.',$actual=$preview(),'Stale observations remain followers and explicitly need a sync: '.$actual);
$seed('missing',static fn(array $cost):bool=>false);
expect(str_ends_with($preview(),'Costs are missing for .missing, .stale. Their prices stay the same until you sync prices.'),'Missing and stale observations are both named');
$seed('norenew',static fn(array $cost):bool=>$cost['action']!=='renew');
$seed('future',static function(array &$cost)use($now):bool{$cost['observed_at']=$now->modify('+1 second')->format(DATE_ATOM);return true;});
$seed('nocost',static function(array &$cost):bool{if($cost['action']==='renew'){$cost['enabled']=false;$cost['total']=null;}return true;});
$excluded=$seed('excluded',static function(array &$cost)use($now):bool{
    if($cost['action']==='transfer'){$cost['enabled']=false;$cost['total']=null;}
    if($cost['action']==='transfer'||$cost['action']==='register'&&$cost['term']===3){$cost['observed_at']=$now->modify('-1 day')->format(DATE_ATOM);$cost['fresh_until']=$now->format(DATE_ATOM);}
    if($cost['action']==='register'&&$cost['term']===1){$cost['enabled']=false;$cost['total']=null;}
    return true;
});$excluded->update(tldColumns(['max_years'=>1],$excluded));
$seed('foreign');DB::table('domains_prices')->where('server_id',$t->server_id)->where('tld','foreign')->update(['account_fingerprint'=>'synthetic-other-binding']);
// Other currency rows cannot make a current USD follower stale or fill a missing cost. A follower on another account counts: the rule is shared.
$eur=$base->first()->replicate();$eur->currency='EUR';$eur->fresh_until=$now;$eur->save();
DB::table('domains_prices')->where('server_id',$tlds[1]->server_id)->update(['fresh_until'=>$now]);
$outside=$tlds[1]->replicate();$outside->tld='outside';$outside->use_default_rule=true;$outsideIntent=OfferState::decode($outside->catalogue_intent);$outsideIntent['pricing_rule']=null;$outside->catalogue_intent=OfferState::json($outsideIntent);$outside->save();
$expected='10 TLDs use Default pricing. Costs are missing for .excluded, .foreign, .future and 3 more. Their prices stay the same until you sync prices.';
$actual=$preview();echo 'Follower Price SELECTs (ten followers): '.count($selects)."\n";
same(1,count($selects),'Ten followers still use one Price SELECT');
same($expected,$actual,'Batched preview retains ordering, current observation warnings and saved costs: '.$actual);
foreach(['growing','more','largest'] as $ending){$seed($ending);}
$text=$preview();same(1,count($selects),'Thirteen followers still use one Price SELECT');
expect(str_starts_with($text,'13 TLDs use Default pricing.'),'Follower count grows');
expect(str_ends_with($text,' Costs are missing for .excluded, .foreign, .future and 3 more. Their prices stay the same until you sync prices.'),'Growing the preview preserves the waiting list');
// A server-held empty observation never falls back to a persisted price or an extra query.
$b=AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->sole());
$query=new Query($b,[$t->tld],'renew',1);
$loaded=$ports->readOffers($query,Context::Holder)[0];$empty=new ReadOffer($loaded,$b,null,Context::Holder);
$selects=[];$evaluating=true;
try{tldRefusal(fn()=>$empty->costPrice($now),'No current normalized cost.');}
finally{$evaluating=false;}
same(0,count($selects),'Empty server-held observation does not query Price again');
$requireCost=static fn(Tld $row)=>$ports->loadBatch([new Query($b,[$row->tld],'renew',1)],Context::Holder)[0][0]->costPrice($now);
same('8.0000',$requireCost($t)->wholesale,'Normal reads require the real annual renewal observation');
$missing=Tld::where('server_id',$t->server_id)->where('tld','missing')->sole();
tldRefusal(fn()=>$requireCost($missing),'No current normalized cost.');
same('8.0000',$requireCost($excluded)->wholesale,'Unselected acquisition terms do not replace the independently proved annual renewal cost');
// The dialog describes saved observations; pending intent still cannot supply a missing annual renewal cost.
foreach(['norenew','nocost'] as $ending){$row=Tld::where('server_id',$t->server_id)->where('tld',$ending)->sole();tldRefusal(fn()=>$requireCost($row),'No current normalized cost.');}
echo "Default follower preview batches USD prices while retaining saved costs and sale exclusions.\n";$nativeSuccessful=true;
