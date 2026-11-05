<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native claims, encrypted identity and synthetic wire callbacks are necessary to prove the real seal.
// Seed an existing holding directly: acquiring it would exercise unrelated transfer/purchase machinery.
// Serial installed checks reuse the pinned, verified read-only vendor fixture.
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';
use App\Models\{Order,Service};
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Facts,OfferState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,DomainManagement,WriteFeatures};
extract(commerceFixture());Queue::fake();
$binding=AccountBindings::binding(Account::where('server_id',$tlds[1]->server_id)->sole());
$tld=$tlds[1]->fresh();$user=commerceUser();$product=$native->product($tld,'register');$plan=$native->annualPlan($product);
$management=new DomainManagement($bindings,$clock,new WriteFeatures(management:true),true);
$contact=['first_name'=>'Synthetic','last_name'=>'Owner','organization_name'=>'','address1'=>'1 Synthetic Street',
    'address2'=>'','city'=>'Seattle','state_province'=>'WA','postal_code'=>'98101','country'=>'US',
    'email_address'=>'contact@example.com','phone'=>'+1.2065550100'];
$wire=(object)['calls'=>[],'during'=>null,'profiles'=>[],'ids'=>[],'next'=>900000];
$exchange=static function($origin,$command,$parameters,$timeout)use($wire):string{
    same(0,DB::transactionLevel(),'Provider I/O remains outside locks');$wire->calls[]=$command;
    if($wire->during){$hook=$wire->during;$wire->during=null;$hook($command);}
    $reply=['code'=>300,'detail'=>'synthetic'];
    if($command==='getDomainInfo'){
        $reply+=['created'=>'2020-01-01','expires'=>'2028-01-01','status'=>'Active','locked'=>'Yes',
            'private'=>'No','auto_renew'=>'No','contact_ids'=>$wire->ids];
    }elseif($command==='contactAdd'){
        $id=(string)++$wire->next;
        $wire->profiles[$id]=array_combine(['first_name','last_name','company','address','address2','city','state','zip','country','email','phone'],array_values($parameters));
        $reply['contact_id']=$id;
    }elseif($command==='contactList'){
        $reply['contact']=$wire->profiles[$parameters['contact_id']]+['contact_id'=>$parameters['contact_id'],'default_profile'=>'0'];
    }elseif($command==='contactDomainAssociate'){$wire->ids=array_diff_key($parameters,['domain'=>true]);}
    else{throw new LogicException('Unexpected synthetic management command');}
    return json_encode(['request'=>['operation'=>$command],'reply'=>$reply]);
};
foreach(['contact_profile','contacts'] as $i=>$action){
    Cache::flush();$clock->instant=$now;$wire->calls=[];$wire->ids=[];
    $name='contact-seal-'.$i.'.com';
    $order=Order::create(['user_id'=>$user->id,'currency_code'=>'USD']);
    $service=Service::withoutEvents(static function()use($user,$order,$product,$plan,$name){
        $s=Service::create(['user_id'=>$user->id,'order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,
            'quantity'=>1,'price'=>'10.40','currency_code'=>'USD','status'=>'active']);
        $s->properties()->create(['key'=>'domain','value'=>$name]);return $s;
    });
    $d=Domain::create(['server_id'=>$binding->serverId,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,
        'user_id'=>$user->id,'service_id'=>$service->id,'domain'=>$name,'sld'=>'contact-seal-'.$i,'tld'=>'com','status'=>'active',
        'provenance'=>'purchased','provider_expires_at'=>'2027-10-18','confirmed_revision'=>1,
        'snapshot'=>['checkout'=>['user_id'=>$user->id,'service_id'=>$service->id,'order_id'=>$order->id,
            'product_id'=>$product->id,'annual_plan_id'=>$plan->id,'name'=>$name,'action'=>'register']]]);
    $driver=new Management($binding,$exchange);$input=['role'=>'all','contact'=>$contact];
    // Each independent case starts with current facts; association has a real durable successful profile.
    $facts=fixtureNameSiloFacts($binding,'com',new Facts(['register'=>[1],'renew'=>[1],'transfer'=>[1]],
        [],true,false,false,'synthetic',$now,$now->modify('+1 day')));
    DB::table('domains_tlds')->where('id',$tld->id)->update(['catalogue_facts'=>OfferState::encodeFacts($facts)]);
    if($action==='contacts'){
        $stage=$management->request($user,$d->id,'contact_profile',$input,hash('sha256','stage-'.$i),$driver);
        same('succeeded',$management->run($stage->id,$driver)->state,'Current evidence permits staging');
        Cache::flush();$wire->calls=[];
    }
    $facts=fixtureNameSiloFacts($binding,'com',new Facts(['register'=>[1],'renew'=>[1],'transfer'=>[1]],
        [],true,false,false,'synthetic',$now->modify('-1 second'),$now->modify('+1 second')));
    DB::table('domains_tlds')->where('id',$tld->id)->update(['catalogue_facts'=>OfferState::encodeFacts($facts)]);
    $op=$management->request($user,$d->id,$action,$input,hash('sha256','expire-'.$i),$driver);
    $wire->during=static function($command)use($clock,$now):void{
        same('getDomainInfo',$command,'Expiry crosses during the ownership preparation read');$clock->instant=$now->modify('+2 seconds');
    };
    $result=$management->run($op->id,$driver);
    same('held',$result->state,'Expired contact facts must refuse before the send: '.$result->state.'; calls='.implode(',',$wire->calls));
    same('absent',$result->attempt['seal'],'Expired contact facts must leave the grant unsealed');
    same('definitely_unsent',$result->attempt['send_state'],'Expired contact facts retain definitely-unsent evidence');
    same([],array_values(array_diff($wire->calls,['getDomainInfo','contactList'])),'Expired contact facts emit no profile creation or association');
    expect(!isset($result->attempt['dispatch_attempted_at']),'Expired facts do not record dispatch');
}
$nativeSuccessful=true;echo "Contact profile and association freshness is rechecked at the locked send seal.\n";
