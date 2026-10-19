<?php
// Exact source declarations and synthetic native rows only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/commerce.php';
require __DIR__.'/staff-panel.php';
$loader->addPsr4('Paymenter\\Extensions\\Servers\\Domains\\',dirname(__DIR__,2).'/Domains/',true);
use App\Models\{Category,Currency,Server,Product,User,Order,Service,Invoice,InvoiceTransaction,NotificationTemplate};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,ReminderTemplates};
use DomainsConversion\{Inputs,SourceSchema,Planner,Mapper,Journal,Converter,Host};
foreach(['Inputs','SourceSchema','Planner','Mapper','Journal','Host','Converter']as$class){require dirname(__DIR__,2).'/scripts/conversion/'.$class.'.php';}
Inputs::revision(defined('CONVERSION_SOURCE_REVISION')?CONVERSION_SOURCE_REVISION:Inputs::REVISION);
Converter::stageSchema();SourceSchema::verify(true);SourceSchema::verify();
config(['queue.default'=>'database','queue.connections.database'=>['driver'=>'database','connection'=>DB::getDefaultConnection(),'table'=>'jobs']]);
Currency::firstOrCreate(['code'=>'USD'],['name'=>'US Dollar','format'=>'1,000.00','prefix'=>'$','suffix'=>'']);
$category=Category::create(['name'=>'Domains','slug'=>'domains']);
$server=Server::create(['name'=>'Legacy','type'=>'server','extension'=>'Enom','enabled'=>true]);
$uid='SyntheticUid';$token='synthetic-token';$old=hash('sha256','test:'.strtolower($uid));
foreach(['uid'=>$uid,'token'=>$token,'environment'=>'test','enom_account_binding'=>$old,'operations_enabled'=>false,'pricing_default_rule'=>['mode'=>'markup_percent','markup'=>'20.00','round_up'=>'none','fixed'=>[]],'reminder_days'=>'30,7','expired_reminder_days'=>'3']as$key=>$value){
    $s=$server->settings()->make(['key'=>$key]);$s->encrypted=in_array($key,['uid','token'],true);$s->type=is_array($value)?'array':(is_bool($value)?'boolean':'string');$s->value=$value;$s->save();}
DB::table('enom_tlds')->insert(['id'=>11,'server_id'=>$server->id,'tld'=>'com','enabled'=>true,'register_enabled'=>true,'renew_enabled'=>true,'transfer_enabled'=>true,'use_default_rule'=>true,
    'cost_register_terms'=>'{"1":"8.0000","3":"7.0000"}','cost_renew'=>'9.0000','cost_transfer'=>'8.0000','renew_price'=>'12.00','costs_synced_at'=>'2026-10-18 00:00:00']);
$product=Product::create(['category_id'=>$category->id,'server_id'=>$server->id,'name'=>'Domain','slug'=>'com-registration','hidden'=>true,'allow_quantity'=>'separated']);
foreach(['enom_catalogue'=>'1','enom_tld_id'=>'11','enom_action'=>'register']as$key=>$value){$product->settings()->create(['key'=>$key,'value'=>$value,'type'=>'string','encrypted'=>false]);}
$plan=$product->plans()->create(['name'=>'Annual','type'=>'recurring','billing_period'=>1,'billing_unit'=>'year']);$plan->prices()->create(['currency_code'=>'USD','price'=>'12.00','setup_fee'=>0]);
DB::table('enom_tlds')->insert(['id'=>12,'server_id'=>$server->id,'tld'=>'co.uk','use_default_rule'=>true]);
$multi=Product::create(['category_id'=>$category->id,'server_id'=>$server->id,'name'=>'Multi-level ending','slug'=>'co-uk-registration','hidden'=>true,'allow_quantity'=>'separated']);
foreach(['enom_catalogue'=>'1','enom_tld_id'=>'12','enom_action'=>'register']as$key=>$value){$multi->settings()->create(['key'=>$key,'value'=>$value,'type'=>'string','encrypted'=>false]);}
$user=User::create(['first_name'=>'Domain','last_name'=>'Owner','email'=>'conversion-owner@example.test','password'=>'password']);
$staff=staffUser(['admin.enom.view','admin.enom.manage-policy']);$evidence=['accounts'=>[(string)$server->id=>['source'=>'authenticated_provider_read','uid'=>strtolower($uid),'environment'=>'test','credential_digest'=>Inputs::digest([$uid,$token]),'accepted_by'=>$staff->id,'accepted_at'=>time(),'reason'=>'conversion_review','domain_id'=>21]],'domains'=>[]];
$definitions=ReminderTemplates::definitions();$template=NotificationTemplate::forceCreate(array_intersect_key(['key'=>'enom_domain_expiring',...$definitions['domains_domain_expiring'],'subject'=>'Customized retained reminder'],array_flip(Schema::getColumnListing('notification_templates'))));
DB::table('credits')->insert(['user_id'=>$user->id,'currency_code'=>'USD','amount'=>'4.25']);
DB::table('notification_preferences')->insert(['user_id'=>$user->id,'notification_template_id'=>$template->id,'mail_enabled'=>false,'in_app_enabled'=>true]);
DB::table('failed_jobs')->insert(['uuid'=>'synthetic-failed-job','connection'=>'database','queue'=>'default','payload'=>'retained payload','exception'=>'retained failure']);
$services=[];$invoices=[];$sourcePayloads=[];
foreach([21=>['purchased.com',true,'active'],22=>['imported.com',false,'active'],23=>['unknown.com',true,'active'],24=>['cancelled.com',false,'cancelled']]as$id=>[$name,$managed,$state]){
    $order=Order::withoutEvents(fn()=>Order::create(['user_id'=>$user->id,'currency_code'=>'USD']));
    $service=Service::withoutEvents(fn()=>Service::create(['order_id'=>$order->id,'product_id'=>$product->id,'plan_id'=>$plan->id,'user_id'=>$user->id,'currency_code'=>'USD','quantity'=>1,'price'=>'12.00','status'=>$id===24?'cancelled':'active','expires_at'=>null]));$services[$id]=$service;$service->properties()->create(['key'=>'domain','name'=>'Domain','value'=>$name]);
    $invoice=Invoice::withoutEvents(fn()=>Invoice::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'paid','due_at'=>'2026-10-18']));
    $item=$invoice->items()->create(['description'=>'Domain purchase','price'=>'12.00','quantity'=>1,'reference_type'=>$service->getMorphClass(),'reference_id'=>$service->id]);
    InvoiceTransaction::withoutEvents(fn()=>InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>'12.00','fee'=>0,'status'=>'succeeded']));$invoices[$id]=$invoice;
    $snapshot=Inputs::encrypt(['contacts'=>['registrant'=>['email'=>'retained@example.test']],'epp'=>'retained-secret','deadline'=>'2026-11-01T00:00:00Z']);$sourcePayloads[$id]=$snapshot;
    DB::table('enom_domains')->insert(['id'=>$id,'service_id'=>$service->id,'user_id'=>$user->id,'server_id'=>$server->id,'domain'=>$name,'reservation_name'=>$name,'sld'=>explode('.',$name)[0],'tld'=>'com','status'=>$state,
        'provider_expires_at'=>'2027-10-18','provider_domain_id'=>(string)(100+$id),'billing_managed'=>$managed,'snapshot'=>$snapshot,'renewal_policy'=>Inputs::encrypt(['automatic_spend_paused'=>false]),'reminders'=>json_encode(['2027-10-18'=>[30]]),'confirmed_revision'=>1]);
    $opId=100+$id;$policy=['billing_managed'=>$managed,'account_fingerprint'=>$old,'authority_generation'=>0,'checkout_product_id'=>$product->id,'checkout_plan_id'=>$plan->id,'checkout_years'=>1,'retail_minor'=>1200,'dispatch_attempted_at'=>'2026-10-18T00:00:00Z'];
    $actual=['state'=>'confirmed','source'=>'GetOrderDetail','provider_order_id'=>(string)$opId,'domain'=>$name,'action'=>'register','term'=>1,'currency'=>'USD','currency_source'=>'documented_orderbillamount_usd','amount'=>'8.00'];
    $cost=['actual'=>$actual,'execution'=>['command'=>'Purchase','status'=>'success','orderid'=>(string)$opId],'refund'=>['credit_id'=>null,'retained_idempotency'=>'original-refund-key']];
    if($id===23){$cost['actual']['state']='unknown';}
    DB::table('enom_operations')->insert(['id'=>$opId,'domain_id'=>$id,'service_id'=>$service->id,'invoice_id'=>$invoice->id,'invoice_item_id'=>$item->id,'operation_key'=>'invoice-item:'.$item->id,'action'=>'register','state'=>$id===23?'uncertain':'succeeded','amount'=>'12.00','currency'=>'USD',
        'confirmed_expires_at'=>$id===23?null:'2027-10-18','payload'=>$snapshot,'policy'=>Inputs::encrypt($policy),'cost'=>Inputs::encrypt($cost),'request_fingerprint'=>hash('sha256','original-'.$id),'provider_order_id'=>(string)$opId,'started_at'=>'2026-10-18 00:00:00','completed_at'=>$id===23?null:'2026-10-18 00:05:00']);
    $evidence['domains'][(string)$id]=['account_fingerprint'=>$old,'xml'=>'<interface-response><Command>GetDomainInfo</Command><Done>true</Done><ErrCount>0</ErrCount><GetDomainInfo><DomainName DomainNameID="'.(100+$id).'">'.$name.'</DomainName><status><belongs-to>'.strtolower($uid).'</belongs-to><expiration>2027-10-18</expiration></status></GetDomainInfo></interface-response>'];
}
// Positive previously renewed holding: original purchase plus independently confirmed renewal/cost quote.
$renewInvoice=Invoice::withoutEvents(fn()=>Invoice::create(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'paid','due_at'=>'2027-10-11']));
$renewItem=$renewInvoice->items()->create(['description'=>'Renew purchased.com','price'=>'12.00','quantity'=>1]);
InvoiceTransaction::withoutEvents(fn()=>InvoiceTransaction::create(['invoice_id'=>$renewInvoice->id,'amount'=>'12.00','fee'=>0,'status'=>'succeeded']));
$renew=(array)DB::table('enom_operations')->where('id',121)->first();$renewCost=Inputs::decrypt($renew['cost']);$renewCost['actual']=array_replace($renewCost['actual'],['action'=>'renew','amount'=>'9.00','provider_order_id'=>'221']);$renewCost['execution']=['command'=>'Extend','status'=>'success','orderid'=>'221'];
$renewCost['quote']=['state'=>'quoted','source'=>'Check+GetExtendInfo','command'=>'Extend','base_expires_at'=>'2027-10-18','complete'=>true,'amount'=>'9.00','currency'=>'USD','domain'=>'purchased.com','action'=>'renew','term'=>1,'server_id'=>$server->id,'account_fingerprint'=>$old,'authority_generation'=>0,'provider_domain_id'=>'121','confirmed_revision'=>1];
DB::table('enom_operations')->insert(array_replace($renew,['id'=>221,'invoice_id'=>$renewInvoice->id,'invoice_item_id'=>$renewItem->id,'operation_key'=>'renew:21:2027-10-18','action'=>'renew','base_expires_at'=>'2027-10-18','expected_expires_at'=>'2028-10-18','confirmed_expires_at'=>'2028-10-18','provider_order_id'=>'221','cost'=>Inputs::encrypt($renewCost)]));
DB::table('enom_domains')->where('id',21)->update(['provider_expires_at'=>'2028-10-18','confirmed_revision'=>2,'reminders'=>json_encode(['2027-10-18'=>[30],'2028-10-18'=>[30]])]);
$evidence['domains']['21']['xml']=str_replace('2027-10-18','2028-10-18',$evidence['domains']['21']['xml']);
$units=['checkout'=>['fixture-http.service'],'schedulers'=>['fixture-scheduler.timer'],'workers'=>['fixture-worker.service'],'dispatch'=>['fixture-dispatch.service']];
app()->instance(Illuminate\Contracts\Foundation\MaintenanceMode::class,new class implements Illuminate\Contracts\Foundation\MaintenanceMode {
    public bool $active=true;public function activate(array $payload):void{$this->active=true;}public function deactivate():void{$this->active=false;}public function active():bool{return $this->active;}public function data():array{return [];}
});
$maintenance=static fn()=>Inputs::maintenance($units,static fn($unit)=>"ActiveState=inactive\nUnitFileState=masked\n");
function conversionRefuses(Closure $call,string $message,string $reason):void {
    try{$call();}catch(Throwable $e){if(($e instanceof DomainException||get_class($e)===RuntimeException::class)&&str_contains($e->getMessage(),$reason)){return;}throw $e;}
    throw new LogicException('Guard accepted: '.$message);
}
$temporary=sys_get_temp_dir().'/conversion-fixture-'.bin2hex(random_bytes(6));mkdir($temporary,0700);
register_shutdown_function(static function()use($temporary):void{foreach(glob($temporary.'/*')?:[]as$p){if(is_file($p)){unlink($p);}}@rmdir($temporary);});
$backup=static function()use($temporary):string {
    foreach(['database','files']as$kind){file_put_contents($temporary.'/'.$kind.'.archive','Synthetic backup '.$kind);}
    $marker=['revision'=>Inputs::selectedRevision(),'source_digest'=>Planner::sourceDigest(),'key_sha256'=>hash('sha256',(string)config('app.key'))];
    foreach(['database','files']as$kind){$p=$temporary.'/'.$kind.'.archive';$marker[$kind]=['path'=>$p,'sha256'=>hash_file('sha256',$p)];}
    file_put_contents($temporary.'/backup.json',json_encode($marker));return $temporary.'/backup.json';
};
