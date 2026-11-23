<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/adoption-confirm.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use App\Models\{Order, Product, Server, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Adoption, Domain};
use Paymenter\Extensions\Servers\Domains\Services\AdoptionEnrollment;
extract(adoptionCommitFixture());
$now=$clock->now()->getTimestamp();
$hostingServer = Server::create(['name'=>'Synthetic hosting','extension'=>'SyntheticHosting','type'=>'server','enabled'=>true]);
$hostingProduct = Product::create(['name'=>'Hosting','slug'=>'adoption-hosting','category_id'=>$product->category_id,'server_id'=>$hostingServer->id,'hidden'=>true,'allow_quantity'=>'combined']);
$hostingPlan = $hostingProduct->plans()->create(['name'=>'Monthly','type'=>'recurring','billing_period'=>1,'billing_unit'=>'month']);
$hostingOrder = Order::create(['user_id'=>$owner->id,'currency_code'=>'USD']);
$hosting = Service::create(['user_id'=>$owner->id,'order_id'=>$hostingOrder->id,'product_id'=>$hostingProduct->id,'plan_id'=>$hostingPlan->id,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
DB::statement("CREATE TRIGGER refuse_initial_queue BEFORE INSERT ON domains_observation_queues BEGIN SELECT RAISE(IGNORE); END");
$r=$reader->preview($target,$context); same('ready',$r->code,'Adoption preview is ready');$a=Adoption::findOrFail($r->previewId);$money=scenarioMoney();$calls=count($wire->calls);
$d=$commit->confirm($a->request_key,$a->request_digest,$staff);
same('committed',$a->fresh()->state,'Enrollment failure never rolls back committed adoption');
DB::statement('DROP TRIGGER refuse_initial_queue');
$enrollment=new AdoptionEnrollment($clock);
// Simulate a database ignoring a required work insert without raising an exception.
foreach([['domains_observation_queues','1'],['domains_observation_work','1']] as [$table,$condition]){
    DB::statement('CREATE TRIGGER refuse_enrollment BEFORE INSERT ON "'.$table.'" WHEN '.$condition.' BEGIN SELECT RAISE(IGNORE); END');
    $refused=false;
    try{$enrollment->repair($d->id);}catch(DomainException){$refused=true;}
    finally{DB::statement('DROP TRIGGER refuse_enrollment');}
    expect($refused,'Missing required '.$table.' '.$condition.' must refuse enrollment');
    expect(!isset($d->fresh()->observation['adoption_enrollment']),'Missing work never commits enrollment marker');
}
// Retained rows with the right unique key but another identity also refuse.
foreach(['domains_observation_queues','domains_observation_work'] as $table){
    $row=match($table){
        'domains_observation_queues'=>['server_id'=>$d->server_id,'account_fingerprint'=>str_repeat('f',64)],
        'domains_observation_work'=>['server_id'=>$d->server_id,'account_fingerprint'=>str_repeat('f',64),'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>'holding','due_at'=>$now],
    };
    $id=DB::table($table)->insertGetId($row);$retained=DB::table($table)->where('id',$id)->first();$refused=false;
    try{$enrollment->repair($d->id);}catch(DomainException){$refused=true;}
    expect($refused,'Conflicting retained '.$table.' identity must refuse');
    expect(!isset($d->fresh()->observation['adoption_enrollment']),'Conflicting work cannot consume marker');
    same((array)$retained,(array)DB::table($table)->where('id',$id)->first(),'Conflicting work is retained without repair');
    DB::table($table)->where('id',$id)->delete();
}
// A late save veto occurs only after all required work has been staged.
$staged=[];$failMarker=true;
Domain::updating(static function(Domain $row)use(&$staged,&$failMarker){
    if($failMarker&&isset($row->observation['adoption_enrollment'])){
        $staged=[DB::table('domains_observation_queues')->count(),DB::table('domains_observation_work')->count()];
        return false;
    }
});
$refused=false;try{$enrollment->repair($d->id);}catch(DomainException $e){same('Adoption enrollment marker was not retained.',$e->getMessage(),'Exact late-save refusal');$refused=true;}
expect($refused,'Late enrollment marker veto refuses');same([1,1],$staged,'Marker veto follows every work insert');
same([0,0],[DB::table('domains_observation_queues')->count(),DB::table('domains_observation_work')->count()],'Veto rolls back registrar enrollment');
expect(!isset($d->fresh()->observation['adoption_enrollment']),'Late veto cannot consume enrollment marker');
$failMarker=false;
// The account cursor repairs the adoption after both early and late enrollment failures.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(AdoptionEnrollment::class,$enrollment);
$neighbor=Domain::create(['user_id'=>$other->id,'server_id'=>$d->server_id,'service_id'=>$hosting->id,'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'domain'=>placeholderComFixtureName('neighbor-placeholder.com'),'sld'=>'neighbor-placeholder','tld'=>'com','status'=>'cancelled']);
DB::table('domains_runtime_turns')->insert(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'domain_cursor'=>$neighbor->id,'due_at'=>0]);
$runtime=new \Paymenter\Extensions\Servers\Domains\Services\RuntimeWork($clock,new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts);
$runtime->tick();same(0,(int)DB::table('domains_runtime_turns')->value('domain_cursor'),'Exhausted cursor wraps for earlier adopted row');
expect(!isset($d->fresh()->observation['adoption_enrollment']),'First turn only wraps cursor');
DB::table('domains_runtime_turns')->update(['due_at'=>0]);$runtime->tick();
same(null,$neighbor->fresh()->observation,'Non-adopted neighbor never acquires adoption marker');
same(1,DB::table('domains_observation_work')->where('domain_id',$d->id)->count(),'Registrar observation enrolled');
$marker=$d->fresh()->observation;
$queries=[];DB::listen(static function($q)use(&$queries):void{$queries[]=$q->sql;});
$enrollment->repair($d->id);
same(1,count($queries),'Current seed marker skips discovery and all native locks');
// Each mismatched seed field reaches discovery, but the locked current marker still wins.
$staleField=null;
Domain::retrieved(static function(Domain $row)use(&$staleField,$d):void{
    if($staleField!==null&&DB::transactionLevel()===0&&(int)$row->id===(int)$d->id){
        $prior=$row->observation;$prior['adoption_enrollment'][$staleField]=-1;$row->observation=$prior;$staleField=null;
    }
});
foreach(['adoption_id','generation']as$field){$staleField=$field;$queries=[];$enrollment->repair($d->id);expect(count($queries)>1,'Changed seed '.$field.' cannot skip locked recheck');same($marker,$d->fresh()->observation,'Locked marker retains original enrollment');}
$commit->confirm($a->request_key,$a->request_digest,$staff);
same($marker,$d->fresh()->observation,'Replay preserves enrollment');same($calls,count($wire->calls),'Repair performs no network reads');same($money,scenarioMoney(),'Repair preserves finances');
$nativeSuccessful=true;echo "Adoption registrar enrollment and repair passed\n";
