<?php
// Batched default follower freshness; MIT.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions;
use Paymenter\Extensions\Servers\Domains\Models\{Price,Tld};
use Paymenter\Extensions\Servers\Domains\Services\PriceCatalogue;
extract(commerceFixture(true));Carbon\Carbon::setTestNow($now);$t=$tlds[0];
$t->update(['use_default_rule'=>true]);
$selects=[];$evaluating=false;
DB::listen(static function($query)use(&$selects,&$evaluating):void{
    if($evaluating&&preg_match('/^select\b.*\bfrom\s+["`]domains_prices["`]/i',$query->sql)){$selects[]=$query->sql;}
});
$followers=new ReflectionMethod(SettingsActions::class,'followers');
$preview=static function()use($followers,$t,&$selects,&$evaluating):string{
    $selects=[];$evaluating=true;try{return $followers->invoke(null,(int)$t->server_id);}finally{$evaluating=false;}
};
same('1 TLD uses the account default: .com. Saving publishes its new prices.',$preview(),'One fresh follower preview');
same(1,count($selects),'One follower uses one Price SELECT');
$base=Price::where('server_id',$t->server_id)->where('tld','com')->orderBy('id')->get();
$seed=static function(string $ending,?callable $change=null)use($t,$base):Tld{
    $next=$t->replicate();$next->tld=$ending;$next->save();
    foreach($base as $price){
        $copy=$price->replicate();$copy->tld=$ending;if($change!==null&&!$change($copy)){continue;}$copy->save();
    }
    return $next;
};
$seed('fresh');
$seed('stale',static function(Price $p)use($now):bool{$p->fresh_until=$now;return true;});
$seed('missing',static fn(Price $p):bool=>false);
$seed('norenew',static fn(Price $p):bool=>$p->action!=='renew');
$seed('future',static function(Price $p)use($now):bool{$p->observed_at=$now->modify('+1 second');return true;});
$seed('nocost',static function(Price $p):bool{if($p->action==='renew'){$p->wholesale=null;}return true;});
$excluded=$seed('excluded',static function(Price $p)use($now):bool{
    if($p->action==='transfer'){$p->eligibility=[...$p->eligibility,'price_unavailable'=>true];$p->fresh_until=$now;}
    if($p->action==='register'&&$p->term===3){$p->fresh_until=$now;}
    if($p->action==='register'&&$p->term===1){$p->wholesale=null;}
    return true;
});$excluded->update(['max_years'=>1]);
$seed('foreign');DB::table('domains_prices')->where('server_id',$t->server_id)->where('tld','foreign')->update(['account_fingerprint'=>'synthetic-other-binding']);
// Other currency and account rows cannot make a current USD follower stale or fill a missing cost.
$eur=$base->first()->replicate();$eur->currency='EUR';$eur->fresh_until=$now;$eur->save();
DB::table('domains_prices')->where('server_id',$tlds[1]->server_id)->update(['fresh_until'=>$now]);
$outside=$tlds[1]->replicate();$outside->tld='outside';$outside->use_default_rule=true;$outside->save();
$expected='9 TLDs use the account default: .com, .excluded, .foreign, .fresh, .future, .missing, .nocost, .norenew, .stale. Saving publishes their new prices. Costs are out of date for .foreign, .future, .missing, .nocost, .norenew, .stale. Their prices stay the same until you sync prices and save again.';
$actual=$preview();echo 'Follower Price SELECTs (nine followers): '.count($selects)."\n";
same(1,count($selects),'Nine followers still use one Price SELECT');
same($expected,$actual,'Batched preview retains ordering, exclusions, renewal requirements and freshness');
foreach(['growing','more','largest'] as $ending){$seed($ending);}
$text=$preview();same(1,count($selects),'Twelve followers still use one Price SELECT');
expect(str_starts_with($text,'12 TLDs use the account default:'),'Follower count grows');
expect(str_ends_with($text,' Costs are out of date for .foreign, .future, .missing, .nocost, .norenew, .stale. Their prices stay the same until you sync prices and save again.'),'Growing the preview preserves the waiting list');
// Null means query normally; an explicitly empty preload must stay empty and never fall back to SQL.
$selects=[];$evaluating=true;
try{tldRefusal(fn()=>PriceCatalogue::requireFreshCosts($t,$now,collect()),'Fresh costs required before publication.');}
finally{$evaluating=false;}
same(0,count($selects),'Empty preload does not query Price again');
PriceCatalogue::requireFreshCosts($t,$now);
$missing=Tld::where('server_id',$t->server_id)->where('tld','missing')->sole();
tldRefusal(fn()=>PriceCatalogue::requireFreshCosts($missing,$now),'Fresh costs required before publication.');
PriceCatalogue::requireFreshCosts($excluded,$now);
echo "Default follower preview batches USD prices while retaining freshness and sale exclusions.\n";$nativeSuccessful=true;
