<?php
// Installed customer DTO ownership and binding acceptance. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';require __DIR__.'/Support/installed.php';require_once __DIR__.'/Support/staff-panel.php';
use App\Models\{Invoice,Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Domains;
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimePorts,AccountBindings,CartAdmission,CheckoutEligibility,InvoiceBinding,NativeCatalogue,PriceCatalogue,OwnedDomains,NativePresenter,OwnerPorts,HolderPricing};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$entry=new Domains;$entry->installed();extract(commerceFixture(true,static fn()=>null));$clock=new FakeClock($now);
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);app()->instance(NativeCatalogue::class,$native);app()->instance(PriceCatalogue::class,$prices);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class,$sellers);
$entry->boot();installedPanel();
$owners=app(OwnedDomains::class);$presenter=app(NativePresenter::class);$user=commerceUser();$user->update(['email'=>'native-owner@example.com']);$foreign=commerceUser();foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}$pairs=[];$checkoutLines=[];$cartEdits=[];
foreach($tlds as $i=>$t){
    if($i){$p=DB::table('domains_sales_policies')->where('tld','com')->sole();$sellers->change('com',$p->revision,$t->server_id,true,true,$now);}
    $cart=commerceCart($user);$item=commerceAdd($cart,$native->product($t,'register'),str_repeat('a',60).$i.'.com');
    $clock->instant=$now->modify('+6 minutes');$expired=commerceCheckout($user,$cart);same($i,\App\Models\Order::count(),'Expired receipt rejects whole native checkout');expect(str_contains(json_encode($expired->effects),'Remove this line'),'Expiry supplies correctable native checkout feedback');$clock->instant=$now;
    $request=\Illuminate\Http\Request::create('/domains/cart/'.$item->id.'/edit','POST',['domain'=>str_repeat('a',59).'x'.$i.'.com','term'=>'1'],['cart'=>$cart->ulid]);$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('session.store'));app()->instance('request',$request);\Illuminate\Support\Once::flush();
    $editController=app(\Paymenter\Extensions\Servers\Domains\Http\Controllers\CartEditController::class);$editView=$editController->edit($request,(int)$item->id);$cartEdits[]=$editView;$request->request->set('_request_key',$editView->getData()['key']);$editController->update($request,(int)$item->id);same(1,$cart->items()->count(),'Neutral edit updates existing native line');$item->refresh();same(str_repeat('a',59).'x'.$i.'.com',$item->checkout_config['domain'],'Native edit applies fresh admission');
    $request->setUserResolver(fn()=>$foreign);try{$editController->edit($request,(int)$item->id);expect(false,'Foreign cart edit refused');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){same(404,$e->getStatusCode(),'Foreign edit private');}$request->setUserResolver(fn()=>$user);
    $line=$presenter->checkout($user,$item);$checkoutLines[]=$line;same('$10.40',$line['renewalPrice'],'Checkout renewal quote from holding retail');
    expect(!str_contains($line['editUrl'],'enom')&&!str_contains($line['editUrl'],'namesilo'),'Neutral checkout edit URL');same(null,$presenter->checkout($foreign,$item),'Foreign cart denied');
    commerceCheckout($user,$cart)->assertHasNoErrors();$d=Domain::where('domain',$item->checkout_config['domain'])->sole();$op=$d->operations()->sole();
    $op->update(['state'=>'held','safe_code'=>'staff_review_required']);$dto=$presenter->service($user,$d->service_id);same('Processing',$dto->status,'No-action held registration is neutral');
    $class='Paymenter\\Extensions\\Servers\\Domains\\Registrars\\'.($i?'NameSilo':'Enom').'\\Management';$actionable=new OwnedDomains($clock,new OwnerPorts(static fn($b)=>new $class($b,static fn()=>throw new RuntimeException('No presenter I/O.'))));
    $op->update(['safe_code'=>'contact_details_required']);same('Needs attention',$actionable->present($d->fresh())->status,'Actionable held registration requests attention');\Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$op->update(['safe_code'=>'staff_review_required']));
    same(null,$presenter->service($foreign,$d->service_id),'Foreign service denied');same([],$presenter->invoice($foreign,$op->invoice_id),'Foreign invoice denied');
    expect(isset($presenter->invoice($user,$op->invoice_id)[$op->invoice_item_id]),'Exact invoice-item binding projected');
    \App\Models\Currency::firstOrCreate(['code'=>'EUR'],['name'=>'Euro','format'=>'1,000.00','prefix'=>'€','suffix'=>'']);DB::table('invoices')->where('id',$op->invoice_id)->update(['currency_code'=>'EUR']);same([],$presenter->invoice($user,$op->invoice_id),'Changed invoice currency refused');DB::table('invoices')->where('id',$op->invoice_id)->update(['currency_code'=>'USD']);
    $pairs[]=str_replace($dto->name,'example.com',json_encode([$dto->status,$dto->expiry,$dto->renewal,$dto->renewalPrice]));
    DB::table('invoice_items')->where('id',$op->invoice_item_id)->update(['reference_id'=>$d->service_id+999]);same([],$presenter->invoice($user,$op->invoice_id),'Mismatched native line denied');DB::table('invoice_items')->where('id',$op->invoice_item_id)->update(['reference_id'=>$d->service_id]);
}
// Advisory pages bound decrypt/financial history independently from complete mutation checks.
$history=DB::table('domains_operations')->where('domain_id',$d->id)->first();$history=(array)$history;unset($history['id']);$history['invoice_item_id']=null;$extras=[];
for($j=0;$j<100;$j++){$history['operation_key']=hash('sha256','advisory-history-'.$j);$extras[]=DB::table('domains_operations')->insertGetId($history);}
try{\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d,null,true);expect(false,'Unbounded advisory history refused');}catch(DomainException $e){expect(str_contains($e->getMessage(),'history'),'Bounded advisory history fails closed');}
DB::table('domains_operations')->whereIn('id',$extras)->delete();
same($pairs[0],$pairs[1],'Installed holding surfaces are identical');
$batch=$presenter->services($user,Domain::pluck('service_id')->all());same(2,count($batch),'Bounded native service batch');
commerceReject(fn()=>$presenter->services($user,array_fill(0,251,1)));
$route=app('router')->getRoutes()->getByName('filament.admin.resources.servers.edit');same(\App\Admin\Resources\ServerResource\Pages\EditServer::class,explode('@',$route->getActionName())[0],'Native generic editor route unchanged');
$staff=staffUser([...array_keys(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()),'admin.servers.viewAny','admin.services.viewAny','admin.servers.view','admin.servers.create','admin.servers.update','admin.services.view','admin.services.update']);
$server=\App\Models\Server::findOrFail($tlds[0]->server_id);$server->settings()->create(['key'=>'private_synthetic_secret','value'=>'SECRET-NEVER-IN-SNAPSHOT','encrypted'=>true]);
// Cached native routes and open pages keep their vanilla classes across hydration.
$open=[\Livewire\Livewire::actingAs($staff)->test(\App\Admin\Resources\ServerResource\Pages\EditServer::class,['record'=>$server->id]),
    \Livewire\Livewire::actingAs($staff)->test(\App\Admin\Resources\ServiceResource\Pages\EditService::class,['record'=>$d->service_id])];
$router=app('router');$allRoutes=$router->getRoutes();$cached=new \Illuminate\Routing\RouteCollection;
foreach($allRoutes as $route){$r=clone $route;$r->prepareForSerialization();$cached->add($r);}
$file=$fixture.'/bootstrap/cache/domains-editor-routes.php';file_put_contents($file,'<?php return '.var_export($cached->compile(),true).';');$router->setCompiledRoutes(require $file);
foreach(['servers'=>$server->id,'services'=>$d->service_id] as $kind=>$id){
    $resource=$kind==='servers'?\App\Admin\Resources\ServerResource::class:\App\Admin\Resources\ServiceResource::class;
    $routeName=$resource::getRouteBaseName(\Filament\Facades\Filament::getCurrentPanel()).'.edit';$matched=$router->getRoutes()->match(\Illuminate\Http\Request::create(route($routeName,['record'=>$id],false)));
    $class=explode('@',$matched->getActionName())[0];same($kind==='servers'?\App\Admin\Resources\ServerResource\Pages\EditServer::class:\App\Admin\Resources\ServiceResource\Pages\EditService::class,$class,'Cached route resolves vanilla editor');
    \Livewire\Livewire::actingAs($staff)->test($class,['record'=>$id])->assertSuccessful()->call('$refresh')->assertSuccessful();
}
foreach($open as $page){$page->call('$refresh')->assertSuccessful();} // Pages mounted before the cache change still hydrate.
$router->setRoutes($allRoutes);app('url')->setRoutes($allRoutes);
expect($router->getRoutes()->getByName('filament.admin.resources.domains.view')!==null,'Native discovered domain route survives cache fixture');
$edit=\Livewire\Livewire::actingAs($staff)->test(\App\Admin\Resources\ServerResource\Pages\EditServer::class,['record'=>$server->id])->assertSuccessful();
expect(str_contains(json_encode($edit->snapshot),'SECRET-NEVER-IN-SNAPSHOT'),'Native server-edit access includes saved credentials');
$processing=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole()->processing_enabled;
$edit->fillForm(['name'=>'Reviewed native registrar'])->call('save')->assertHasNoFormErrors();
same($processing,\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole()->processing_enabled,'Name-only native save preserves processing');
\Livewire\Livewire::actingAs($staff)->test(\App\Admin\Resources\ServerResource\Pages\CreateServer::class)->fillForm(['name'=>'New installed registrar','extension'=>'Domains'])->call('create')->assertHasNoFormErrors();
expect(\App\Models\Server::where('name','New installed registrar')->exists(),'Native create form installs before persistence');
$service=Service::findOrFail($d->service_id);$price=$service->getRawOriginal('price');
\Livewire\Livewire::actingAs($staff)->test(\App\Admin\Resources\ServiceResource\Pages\EditService::class,['record'=>$service->id])->call('save')->assertHasNoFormErrors();
same($price,$service->fresh()->getRawOriginal('price'),'Native unchanged price save keeps financial identity');same(null,$service->fresh()->expires_at,'Native expiry remains absent');
$legacy=\App\Models\Server::create(['name'=>'Paused legacy','type'=>'server','extension'=>'Enom','enabled'=>false]);$setting=$legacy->settings()->create(['key'=>'operations_enabled','type'=>'boolean','value'=>false]);
$oldRequest=app('request');app()->instance('request',Illuminate\Http\Request::create('/livewire/update','POST'));
try{$setting->update(['value'=>true]);expect(false,'Legacy toggle refused');}catch(Illuminate\Validation\ValidationException $e){expect(isset($e->errors()['data.settings.operations_enabled']),'Legacy refusal has native field feedback');}finally{app()->instance('request',$oldRequest);}
DB::table('settings')->where('id',$setting->id)->update(['value'=>'1']);
\Livewire\Livewire::actingAs($staff)->test(\App\Admin\Resources\ServerResource\Pages\CreateServer::class)->fillForm(['name'=>'Refused native registrar','extension'=>'Domains'])->call('create')->assertHasFormErrors(['extension']);
expect(!\App\Models\Server::where('name','Refused native registrar')->exists(),'Active legacy refusal precedes persistence');DB::table('settings')->where('id',$setting->id)->update(['value'=>'0']);
echo "Installed paired native checkout, held status, service/batch/invoice DTO and foreign-owner refusal passed.\n";$nativeSuccessful=true;
