<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Order,Service,Property,Product,ServiceConfig,Setting};
use Illuminate\Support\Facades\{DB,Queue};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\NativeIdentity;
Queue::fake();extract(commerceFixture());$owner=commerceUser();$other=commerceUser();
$p=$native->product($tlds[0],'register');$hosting=commerceHosting();
$make=function(Product $product,string $name,?int $planId=null)use($owner):Service {
    $o=Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
    $s=Service::create(['order_id'=>$o->id,'user_id'=>$owner->id,'product_id'=>$product->id,
        'plan_id'=>$planId??$product->plans()->where('billing_period',1)->firstOrFail()->id,'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'pending']);
    $s->properties()->create(['key'=>'domain','name'=>'Domain','value'=>$name]);return $s;
};
$s=$make($p,'before-ledger-placeholder.com');$host=$make($hosting,'hosting.example');
$property=$s->properties()->where('key','domain')->sole();$order=$s->order;
$coupon=App\Models\Coupon::create(['code'=>'identity','type'=>'fixed','value'=>1]);$s->coupon_id=$coupon->id;$s->save();
$parent=DB::table('config_options')->insertGetId(['name'=>'parent']);
$option=DB::table('config_options')->insertGetId(['name'=>'option']);
$value=DB::table('config_options')->insertGetId(['name'=>'value','parent_id'=>$parent]);
$config=DB::table('service_configs')->insertGetId(['configurable_type'=>$s->getMorphClass(),'configurable_id'=>$s->id,'config_option_id'=>$option,'config_value_id'=>$value]);
$definition=App\Models\CustomProperty::create(['name'=>'Domain','key'=>'domain','model'=>Service::class,'type'=>'text','non_editable'=>false,'required'=>false,'show_on_invoice'=>false]);
$property->custom_property_id=$definition->id;$property->save();
// The competitor listener is before the guard; the SQL sentinel is registered after it.
$armed=null;$after=false;$numericArmed=false;
foreach([Order::class,Service::class,Property::class] as $class){$class::saving(static function($row)use(&$armed):void {
    if($armed===get_class($row)){Fiber::suspend('before-guard');}
});}
foreach([Order::class,Service::class,Property::class] as $class){$class::updating(static function($row)use(&$armed,&$numericArmed):void {
    if($numericArmed&&$armed===get_class($row)){Fiber::suspend('before-guard');}
});}
NativeIdentity::boot();NativeIdentity::boot();
foreach([Order::class,Service::class,Property::class] as $class){$class::updating(static function($row)use(&$armed,&$after):void {
    if($armed===get_class($row)){$after=true;Fiber::suspend('after-guard-before-sql');}
});}
$checkingIdentityMutation=false;
// A refused operation must stop before the terminal model event reaches SQL, including FK failures.
foreach([Order::class,Service::class,Property::class,ServiceConfig::class,Product::class,Setting::class,
    App\Models\Plan::class,App\Models\Server::class,App\Models\Extension::class,App\Models\User::class,
    App\Models\Category::class,App\Models\CustomProperty::class,App\Models\Coupon::class,App\Models\ConfigOption::class] as $class){
    foreach($class===Order::class?['updating','deleting']:['creating','updating','deleting'] as $event){$class::$event(static function()use(&$checkingIdentityMutation):void {
        expect(!$checkingIdentityMutation,'Protected native write reached the SQL gap');
    });}
}
function identityReject(callable $attempt,string $label):void {
    global $checkingIdentityMutation;$checkingIdentityMutation=true;$refused=false;
    try{$attempt();}catch(DomainException $e){same(str_contains($label,'expiry')?\Paymenter\Extensions\Servers\Domains\Services\NativeExpiry::NUMERIC_MESSAGE:'This change affects managed domain identity and was refused.',$e->getMessage(),$label.' safe refusal');$refused=true;}finally{$checkingIdentityMutation=false;}
    expect($refused,$label.' refused before mutation');
}
// Uncast native booleans retain their SQL meaning on fresh and retrieved catalogue saves.
$published=Product::create(['name'=>'Native stock control','slug'=>'domains-enom-s'.$p->server_id.'-positive-registration','server_id'=>$p->server_id,'category_id'=>$p->category_id,'hidden'=>true,'allow_quantity'=>'separated','stock'=>null]);
$published->stock=null;$published->save();same(null,$published->fresh()->stock,'New routed product stock save remains native');
$published=$published->fresh();$published->hidden=true;$published->name='Native display control';$published->save();
same('Native display control',$published->fresh()->name,'Equivalent retrieved boolean does not refuse display save');
same(0,Domain::count(),'Protection must precede every ledger row');
$newConfig=static fn(array $row)=> (new ServiceConfig)->forceFill($row)->save();
$emptyMarkerProduct=Product::create(['name'=>'Unused route','slug'=>'unused-route-marker','category_id'=>$p->category_id,
    'server_id'=>$p->server_id,'hidden'=>true,'allow_quantity'=>'separated']);
$emptyMarkerProduct->settings()->create(['key'=>'domains_catalogue','value'=>'1']);
$emptyServer=App\Models\Server::create(['name'=>'Empty route server','type'=>'server','extension'=>'Domains','enabled'=>false]);

$clean=static function(string $model,int $id,string $field,mixed $value) {
    $table=(new $model)->getTable();$writer=$model::select($table.'.id')->selectRaw('? AS '.$field,[$value])->findOrFail($id);
    expect(!$writer->isDirty(),'Projected numeric-extra fixture starts clean');return $writer;
};
$rejects=[
    'clean service owner'=>fn()=>$clean(Service::class,$s->id,'user_id',$other->id)->increment('id',0,['user_id'=>$other->id]),
    'clean order owner'=>fn()=>$clean(Order::class,$order->id,'user_id',$other->id)->decrement('id',0,['user_id'=>$other->id]),
    'clean canonical property'=>fn()=>$clean(Property::class,$property->id,'value','clean-escape-placeholder.com')->increment('id',0,['value'=>'clean-escape-placeholder.com']),
    'json-selector property'=>fn()=>$property->fresh()->increment('id',0,['value->alias'=>'escape']),
    'case service owner'=>fn()=>$s->fresh()->increment('id',0,['USER_ID'=>$other->id]),
    'case order owner'=>fn()=>$order->fresh()->decrement('id',0,['USER_ID'=>$other->id]),
    'case property value'=>fn()=>$property->fresh()->increment('id',0,['VALUE'=>'case-escape-placeholder.com']),
    'case property morph'=>fn()=>$property->fresh()->decrement('id',0,['MODEL_ID'=>$host->id]),
    'case configuration morph'=>fn()=>ServiceConfig::findOrFail($config)->increment('id',0,['CONFIGURABLE_ID'=>$host->id]),
    'case marker morph'=>fn()=>$p->settings()->firstOrFail()->decrement('id',0,['SETTINGABLE_ID'=>$hosting->id]),
    'case proposed route'=>fn()=>$host->fresh()->increment('id',0,['PRODUCT_ID'=>$p->id]),
    'case expiry'=>fn()=>$s->fresh()->increment('id',0,['EXPIRES_AT'=>'2028-01-01']),
    'case property key onto route'=>fn()=>$host->properties()->where('key','domain')->sole()->increment('id',0,['MODEL_ID'=>$s->id]),
    'numeric property value'=>fn()=>$property->fresh()->increment('id',0,['value'=>'numeric-escape-placeholder.com']),
    'numeric property morph'=>fn()=>$property->fresh()->decrement('id',0,['model_id'=>$host->id]),
    'numeric configuration'=>fn()=>ServiceConfig::findOrFail($config)->increment('id',0,['config_value_id'=>$option]),
    'numeric configuration morph'=>fn()=>ServiceConfig::findOrFail($config)->decrement('id',0,['configurable_id'=>$host->id]),
    'numeric marker'=>fn()=>$p->settings()->firstOrFail()->increment('id',0,['value'=>'numeric-escape']),
    'numeric marker morph'=>fn()=>$p->settings()->firstOrFail()->decrement('id',0,['settingable_id'=>$hosting->id]),
    'numeric service owner'=>fn()=>$s->fresh()->increment('id',0,['user_id'=>$other->id]),
    'numeric order owner'=>fn()=>$order->fresh()->decrement('id',0,['user_id'=>$other->id]),
    'numeric expiry increment'=>fn()=>$s->fresh()->increment('id',0,['expires_at'=>'2028-01-01']),
    'numeric expiry decrement'=>fn()=>$s->fresh()->decrement('id',0,['expires_at'=>'2028-01-01']),
    'partial empty server'=>fn()=>App\Models\Server::select('id')->findOrFail($emptyServer->id)->delete(),
    'partial empty server identity'=>fn()=>App\Models\Server::select('id')->findOrFail($emptyServer->id)->update(['extension'=>'Other']),
    'partial service owner'=>fn()=>Service::select('id')->findOrFail($s->id)->update(['user_id'=>$other->id]),
    'partial service delete'=>fn()=>Service::select('id')->findOrFail($s->id)->delete(),
    'partial canonical property'=>fn()=>Property::select('id','value')->findOrFail($property->id)->update(['value'=>'partial-escape-placeholder.com']),
    'partial property delete'=>fn()=>Property::select('id')->findOrFail($property->id)->delete(),
    'partial config'=>fn()=>ServiceConfig::select('id')->findOrFail($config)->update(['config_value_id'=>$option]),
    'partial product'=>fn()=>Product::select('id')->findOrFail($p->id)->delete(),
    'partial plan'=>fn()=>App\Models\Plan::select('id')->findOrFail($s->plan_id)->delete(),
    'partial marker'=>fn()=>Setting::select('id','value')->findOrFail($p->settings()->firstOrFail()->id)->update(['value'=>'partial-escape']),
    'marker duplicate without services or plans'=>fn()=>$emptyMarkerProduct->settings()->create(['key'=>'domains_catalogue','value'=>'1']),
    'service currency'=>fn()=>$s->fresh()->update(['currency_code'=>'EUR']),
    'order currency'=>fn()=>$order->fresh()->update(['currency_code'=>'EUR']),
    'configuration delete'=>fn()=>ServiceConfig::findOrFail($config)->delete(),
    'configuration value'=>fn()=>ServiceConfig::findOrFail($config)->update(['config_value_id'=>$option]),
    'configuration move off'=>fn()=>ServiceConfig::findOrFail($config)->forceFill(['configurable_id'=>$host->id])->save(),
    'configuration morph off'=>fn()=>ServiceConfig::findOrFail($config)->forceFill(['configurable_type'=>$other->getMorphClass()])->save(),
    'configuration new'=>fn()=>$newConfig(['configurable_type'=>$s->getMorphClass(),'configurable_id'=>$s->id,'config_option_id'=>$option,'config_value_id'=>$value]),
    'configuration proposed route'=>fn()=>ServiceConfig::findOrFail($config)->replicate()->forceFill(['configurable_id'=>$s->id])->save(),
    'marker duplicate on route'=>fn()=>$p->settings()->create(['key'=>'domains_catalogue','value'=>'1']),
    'marker new on hosting'=>fn()=>$hosting->settings()->create(['key'=>'domains_catalogue','value'=>'1']),
    'marker edit'=>fn()=>$p->settings()->firstOrFail()->update(['value'=>'corrupt']),
    'marker move'=>fn()=>$p->settings()->firstOrFail()->forceFill(['settingable_id'=>$hosting->id])->save(),
    'product hidden'=>fn()=>$p->fresh()->update(['hidden'=>false]),
    'product quantity'=>fn()=>$p->fresh()->update(['allow_quantity'=>'combined']),
    'product slug'=>fn()=>$p->fresh()->update(['slug'=>'hosting-escape']),
    'product delete'=>fn()=>$p->fresh()->delete(),
    'plan delete'=>fn()=>App\Models\Plan::findOrFail($s->plan_id)->delete(),
    'plan proposed route'=>fn()=>App\Models\Plan::findOrFail($host->plan_id)->forceFill(['priceable_id'=>$p->id])->save(),
    'product proposed route'=>fn()=>$hosting->fresh()->update(['server_id'=>$p->server_id,'stock'=>null]),
    'order delete'=>fn()=>$order->fresh()->delete(),
    'service delete'=>fn()=>$s->fresh()->delete(),
    'property delete'=>fn()=>$property->fresh()->delete(),
    'order model'=>fn()=>$order->fresh()->update(['user_id'=>$other->id]),
    'service order'=>fn()=>$s->fresh()->update(['order_id'=>$host->order_id]),
    'service plan'=>fn()=>$s->fresh()->update(['plan_id'=>$host->plan_id]),
    'service quantity'=>fn()=>$s->fresh()->update(['quantity'=>2]),
    'service coupon'=>fn()=>$s->fresh()->update(['coupon_id'=>null]),
    'service key'=>function()use($s){$r=$s->fresh();$r->id=$r->id+100;$r->save();},
    'order key'=>function()use($order){$r=$order->fresh();$r->id=$r->id+100;$r->save();},
    'property identity'=>function()use($property){$r=$property->fresh();$r->id=$r->id+100;$r->save();},
    'property definition'=>fn()=>$property->fresh()->update(['custom_property_id'=>null]),
    'property key'=>fn()=>$property->fresh()->update(['key'=>'label']),
    'property type'=>fn()=>$property->fresh()->update(['model_type'=>$other->getMorphClass()]),
    'property move onto route'=>fn()=>$host->properties()->where('key','domain')->sole()->update(['model_id'=>$s->id]),
    'property duplicate'=>fn()=>$s->properties()->create(['key'=>'domain','value'=>'duplicate-placeholder.com']),
    'property definition cascade'=>fn()=>$definition->delete(),
    'route category cascade'=>fn()=>$p->category->delete(),
    'service model'=>fn()=>$s->fresh()->update(['user_id'=>$other->id]),
    'service product'=>fn()=>$s->fresh()->update(['product_id'=>$hosting->id]),
    'service proposed route'=>fn()=>$host->fresh()->update(['product_id'=>$p->id]),
    'property model'=>fn()=>$property->fresh()->update(['value'=>'changed-placeholder.com']),
    'property move off route'=>fn()=>$property->fresh()->update(['model_id'=>$host->id]),
    'product escape'=>fn()=>$p->fresh()->update(['server_id'=>null]),
    'marker strip'=>fn()=>$p->settings()->firstOrFail()->delete(),
    'plan escape'=>fn()=>App\Models\Plan::findOrFail($s->plan_id)->update(['billing_unit'=>'month']),
    'server escape'=>fn()=>App\Models\Server::findOrFail($p->server_id)->update(['extension'=>'Other']),
    'coupon cascade'=>fn()=>App\Models\Coupon::findOrFail($coupon->id)->delete(),
    'configuration value cascade'=>fn()=>App\Models\ConfigOption::findOrFail($value)->delete(),
    'configuration recursive cascade'=>fn()=>App\Models\ConfigOption::findOrFail($parent)->delete(),
    'configuration option cascade'=>fn()=>App\Models\ConfigOption::findOrFail($option)->delete(),
    'owner cascade'=>fn()=>App\Models\User::findOrFail($owner->id)->delete(),
];
foreach($rejects as $label=>$attempt){identityReject($attempt,$label);
    same($owner->id,(int)$s->fresh()->user_id,$label.' retains service owner');
    same($owner->id,(int)$order->fresh()->user_id,$label.' retains order owner');
    same('before-ledger-placeholder.com',$property->fresh()->value,$label.' retains name');
    same(0,DB::transactionLevel(),$label.' does not leak transactions');
}
// Original and proposed config morphs are checked separately, including a hosting config moved onto the route.
$hostConfig=(new ServiceConfig)->forceFill(['configurable_type'=>$host->getMorphClass(),'configurable_id'=>$host->id,'config_option_id'=>$option,'config_value_id'=>$value]);$hostConfig->save();
identityReject(fn()=>$hostConfig->fresh()->forceFill(['configurable_id'=>$s->id])->save(),'configuration hosting onto route');
$hostConfig->increment('id',0,['config_value_id'=>$option]);$hostConfig->decrement('id',0,['config_value_id'=>$value]);$hostConfig->delete();
// Projected hosting records still use ordinary event-enabled identity and property saves.
Service::select('id')->findOrFail($host->id)->update(['user_id'=>$other->id]);
same((int)$other->id,(int)$host->fresh()->user_id,'Partial hosting owner save remains native');
$host->fresh()->update(['user_id'=>$owner->id]);
Property::select('id','value')->findOrFail($host->properties()->where('key','domain')->sole()->id)->update(['value'=>'partial-host.example']);
same('partial-host.example',$host->properties()->where('key','domain')->sole()->value,'Partial hosting property save remains native');
// Native status changes, including paid Active, remain native. Route expiry is silently neutralized.
foreach(['active','suspended','cancelled','pending'] as $status){
    $s->fresh()->update(['status'=>$status,'expires_at'=>'2028-01-01']);
    same($status,$s->fresh()->status,'Native lifecycle status remains writable');same(null,$s->fresh()->expires_at,'Route expiry remains null');
}
$s->fresh()->increment('id',0,['status'=>'active','expires_at'=>null]);
same('active',$s->fresh()->status,'Numeric native paid status remains writable');
$host->fresh()->decrement('id',0,['expires_at'=>'2028-01-01']);expect($host->fresh()->expires_at!==null,'Hosting numeric expiry remains native');
$hp=$host->properties()->where('key','domain')->sole();$hp->increment('id',0,['value'=>'numeric-host.example']);
same('numeric-host.example',$hp->fresh()->value,'Hosting numeric Property remains native');
$hostSetting=$hosting->settings()->create(['key'=>'hosting-label','value'=>'before']);$hostSetting->decrement('id',0,['value'=>'after']);
same('after',$hostSetting->fresh()->value,'Hosting numeric Setting remains native');
$host->fresh()->increment('id',0,['USER_ID'=>$other->id]);same((int)$other->id,(int)$host->fresh()->user_id,'Hosting case owner remains native');
$host->fresh()->update(['user_id'=>$owner->id]);$host->order->increment('id',0,['USER_ID'=>$other->id]);
same((int)$other->id,(int)$host->order->fresh()->user_id,'Hosting case order remains native');$host->order->fresh()->update(['user_id'=>$owner->id]);
$hp->fresh()->decrement('id',0,['VALUE'=>'case-host.example']);same('case-host.example',$hp->fresh()->value,'Hosting case Property remains native');
$clean(Service::class,$host->id,'user_id',$other->id)->increment('id',0,['user_id'=>$other->id]);
same((int)$other->id,(int)$host->fresh()->user_id,'Clean projected hosting owner remains native');$host->fresh()->update(['user_id'=>$owner->id]);
$clean(Property::class,$hp->id,'value','clean-host.example')->decrement('id',0,['value'=>'clean-host.example']);
same('clean-host.example',$hp->fresh()->value,'Clean projected hosting Property remains native');
$clean(Service::class,$s->id,'status','active')->increment('id',0,['status'=>'active']);
same('active',$s->fresh()->status,'Clean projected native paid status remains writable');
// Every recognized signal protects a malformed route even without markers, server or ledger.
foreach(['slug','server','marker','plan'] as $signal){
    $malformed=commerceHosting();$malformed->slug='malformed-'.$signal;$malformed->save();
    $routeService=$make($malformed,'malformed-'.$signal.'.example');
    if($signal==='slug'){DB::table('products')->where('id',$malformed->id)->update(['slug'=>'domains-enom-s999-invalid-registration']);}
    if($signal==='server'){DB::table('products')->where('id',$malformed->id)->update(['server_id'=>$p->server_id]);}
    if($signal==='marker'){Setting::withoutEvents(fn()=>$malformed->settings()->create(['key'=>'domains_tld_id','value'=>'invalid']));}
    if($signal==='plan'){DB::table('services')->where('id',$routeService->id)->update(['plan_id'=>$s->plan_id]);}
    identityReject(fn()=>$routeService->fresh()->update(['user_id'=>$other->id]),$signal.' original route');
    identityReject(fn()=>$make($malformed->fresh(),'unadmitted.example',$signal==='plan'?$s->plan_id:null),$signal.' direct creation');
}
// Neither FK side may disappear when a maintenance-corrupted pair still has a route signal.
$badProduct=commerceHosting();$badProduct->slug='mismatched-hosting';$badProduct->save();
$badPlan=$badProduct->plans()->sole();$a=$make($badProduct,'mismatch-product.example');$b=$make($badProduct,'mismatch-plan.example');
DB::table('services')->where('id',$a->id)->update(['plan_id'=>$s->plan_id]);
DB::table('services')->where('id',$b->id)->update(['product_id'=>$p->id]);
identityReject(fn()=>$badProduct->fresh()->delete(),'Hosting product with route plan is retained');
identityReject(fn()=>$badProduct->fresh()->update(['slug'=>'escape-malformed']),'Hosting product identity with route plan is retained');
identityReject(fn()=>$badPlan->fresh()->delete(),'Hosting plan with route product is retained');
identityReject(fn()=>$badPlan->fresh()->update(['billing_period'=>2]),'Hosting plan identity with route product is retained');
$unrelated=commerceHosting();$unrelated->slug='unrelated-hosting';$unrelated->save();$unrelated->plans()->sole()->delete();$unrelated->delete();
// The reserved publication category is retained even before its first routed product exists.
// Temporarily move the fixture category so the native unique slug permits an empty publication root.
$publishedCategory=App\Models\Category::where('slug','domains')->sole();
DB::table('categories')->where('id',$publishedCategory->id)->update(['slug'=>'published-fixture']);
$emptyRoute=App\Models\Category::create(['name'=>'Domains','slug'=>'domains']);
identityReject(fn()=>App\Models\Category::select('id')->findOrFail($emptyRoute->id)->delete(),'Empty publication category deletion');
identityReject(fn()=>$emptyRoute->fresh()->update(['slug'=>'escape-publication']),'Empty publication category rename');
DB::table('categories')->where('id',$emptyRoute->id)->delete();
DB::table('categories')->where('id',$publishedCategory->id)->update(['slug'=>'domains']);
$emptyHosting=App\Models\Category::create(['name'=>'Hosting','slug'=>'unrelated-empty-hosting']);$emptyHosting->delete();
// Hosting-only parent deletion remains an ordinary native action without an outer transaction.
$hostingOwner=commerceUser();$hostingOrder=Order::create(['user_id'=>$hostingOwner->id,'currency_code'=>'USD']);
$hostingService=Service::create(['user_id'=>$hostingOwner->id,'order_id'=>$hostingOrder->id,'product_id'=>$hosting->id,
    'plan_id'=>$host->plan_id,'quantity'=>1,'currency_code'=>'USD','status'=>'pending','price'=>5]);
$hostingCoupon=App\Models\Coupon::create(['code'=>'hosting-only','type'=>'fixed','value'=>1]);
$hostingService->update(['coupon_id'=>$hostingCoupon->id]);$hostingCoupon->delete();same(null,$hostingService->fresh()->coupon_id,'Hosting coupon deletion remains native');
$hostingOwner->delete();expect(!Service::whereKey($hostingService->id)->exists(),'Hosting-only owner deletion still cascades normally');
// Adoption-first: resume the exact native writer after lock-and-synthetic-insert commits.
// This is a controllable scheduling proof, not a claim of concurrent SQLite row locks.
foreach(['order-owner'=>Order::class,'service-owner'=>Service::class,'service-order'=>Service::class,'domain-property'=>Property::class,'partial-service-owner'=>Service::class,'partial-domain-property'=>Property::class,'numeric-domain-property'=>Property::class,'case-order-owner'=>Order::class,'case-service-owner'=>Service::class,'case-domain-property'=>Property::class,'clean-order-owner'=>Order::class,'clean-service-owner'=>Service::class,'clean-domain-property'=>Property::class] as $edit=>$class){
    $race=$make($hosting,'race-'.$edit.'.example');
    DB::table('services')->where('id',$race->id)->update(['product_id'=>$p->id,'plan_id'=>$s->plan_id]);$race->refresh();
    $raceOrder=$race->order;$raceProperty=$race->properties()->where('key','domain')->sole();$armed=$class;$after=false;$numericArmed=$edit==='numeric-domain-property'||str_starts_with($edit,'case-')||str_starts_with($edit,'clean-');
    $competitor=new Fiber(function()use($edit,$race,$raceOrder,$raceProperty,$host,$other,$clean):void {
        identityReject(match($edit){
            'order-owner'=>fn()=>$raceOrder->fresh()->update(['user_id'=>$other->id]),
            'service-owner'=>fn()=>$race->fresh()->update(['user_id'=>$other->id]),
            'service-order'=>fn()=>$race->fresh()->update(['order_id'=>$host->order_id]),
            'domain-property'=>fn()=>$raceProperty->fresh()->update(['value'=>'reassigned.example']),
            'partial-service-owner'=>fn()=>Service::select('id')->findOrFail($race->id)->update(['user_id'=>$other->id]),
            'partial-domain-property'=>fn()=>Property::select('id','value')->findOrFail($raceProperty->id)->update(['value'=>'reassigned.example']),
            'numeric-domain-property'=>fn()=>$raceProperty->fresh()->increment('id',0,['value'=>'reassigned.example']),
            'case-order-owner'=>fn()=>$raceOrder->fresh()->increment('id',0,['USER_ID'=>$other->id]),
            'case-service-owner'=>fn()=>$race->fresh()->decrement('id',0,['USER_ID'=>$other->id]),
            'case-domain-property'=>fn()=>$raceProperty->fresh()->increment('id',0,['VALUE'=>'reassigned.example']),
            'clean-order-owner'=>fn()=>$clean(Order::class,$raceOrder->id,'user_id',$other->id)->increment('id',0,['user_id'=>$other->id]),
            'clean-service-owner'=>fn()=>$clean(Service::class,$race->id,'user_id',$other->id)->decrement('id',0,['user_id'=>$other->id]),
            'clean-domain-property'=>fn()=>$clean(Property::class,$raceProperty->id,'value','reassigned.example')->increment('id',0,['value'=>'reassigned.example'])
        },$edit.' paused competitor');
    });
    same('before-guard',$competitor->start(),$edit.' paused before guard');
    DB::transaction(function()use($race,$raceOrder,$owner,$p,$tlds,$edit):void {
        $owners=\Paymenter\Extensions\Servers\Domains\Services\HoldingIdentityLock::lockForAdoption($raceOrder->id,$race->id)->owners($owner->id);
        same($owner->id,$owners['service_user_id'],'Adoption rechecked native owner');
        Domain::create(['service_id'=>$race->id,'user_id'=>$owner->id,'domain'=>placeholderComFixtureName('race-'.$edit.'-placeholder.com'),'sld'=>'race-'.$edit.'-placeholder','tld'=>'com',
            'server_id'=>$p->server_id,'registrar_key'=>'enom','account_fingerprint'=>$tlds[0]->account_fingerprint,'status'=>'registration_pending']);
    });
    $competitor->resume();expect($competitor->isTerminated(),$edit.' refused after adoption commits');
    same(false,$after,$edit.' can never pass the guard to reach the SQL gap');
    same($owner->id,(int)$race->fresh()->user_id,'Service owner retained');same($owner->id,(int)$raceOrder->fresh()->user_id,'Order owner retained');
    same($raceOrder->id,(int)$race->fresh()->order_id,'Order relation retained');same('race-'.$edit.'.example',$raceProperty->fresh()->value,'Route property retained');$armed=null;$numericArmed=false;
}
// Hosting reaches the post-guard SQL gap and commits normally.
$armed=Service::class;$after=false;$competitor=new Fiber(fn()=>$host->fresh()->update(['user_id'=>$other->id]));
same('before-guard',$competitor->start(),'Hosting before guard');same('after-guard-before-sql',$competitor->resume(),'Hosting after guard');$armed=null;$competitor->resume();
expect($after&&$competitor->isTerminated(),'Hosting continues to SQL');same($other->id,(int)$host->fresh()->user_id,'Hosting owner saved');
$host->fresh()->update(['expires_at'=>'2028-01-01']);expect($host->fresh()->expires_at!==null,'Hosting expiry unchanged');
$host->properties()->where('key','domain')->sole()->update(['value'=>'new-host.example']);
same('new-host.example',$host->properties()->where('key','domain')->sole()->value,'Hosting property model remains writable');
// Privileged maintenance controls are explicit; they are not activation safety claims.
same(1,DB::table('orders')->where('id',$order->id)->update(['user_id'=>$other->id]),'Builder bulk maintenance excluded');
$order->fresh()->updateQuietly(['user_id'=>$owner->id]);same($owner->id,(int)$order->fresh()->user_id,'Quiet maintenance excluded');
DB::connection()->getPdo()->exec('update orders set user_id = '.(int)$other->id.' where id = '.(int)$order->id);
same($other->id,(int)$order->fresh()->user_id,'Direct PDO maintenance excluded');
DB::table('orders')->where('id',$order->id)->update(['user_id'=>$owner->id]);
// Publication still creates products/plans/markers through ordinary native models.
$native->publish($tlds[0]->id,$now);
echo 'Model-event native identity guards: '.count($rejects)." refusals, pre-ledger/malformed route and hosting controls passed.\n";
$nativeSuccessful=true;
