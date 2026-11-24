<?php
// Installed customer DTO ownership and binding acceptance. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$pricingNoticeScopes=require __DIR__.'/Support/pricing-editor-notice-scopes.php';
require __DIR__.'/Support/runtime-presenter-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
// Advisory pages bound decrypt/financial history independently from complete mutation checks.
$history=DB::table('domains_operations')->where('domain_id',$d->id)->first();$history=(array)$history;unset($history['id']);$history['invoice_item_id']=null;$extras=[];
for($j=0;$j<100;$j++){$history['operation_key']=hash('sha256','advisory-history-'.$j);$extras[]=DB::table('domains_operations')->insertGetId($history);}
try{\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d,null,true);expect(false,'Unbounded advisory history refused');}catch(DomainException $e){expect(str_contains($e->getMessage(),'history'),'Bounded advisory history fails closed');}
DB::table('domains_operations')->whereIn('id',$extras)->delete();
same($pairs[0],$pairs[1],'Installed holding surfaces are identical');
$batch=$presenter->services($user,Domain::pluck('service_id')->all());same(2,count($batch),'Bounded native service batch');
commerceReject(fn()=>$presenter->services($user,array_fill(0,251,1)));
$route=app('router')->getRoutes()->getByName('filament.admin.resources.servers.edit');same(\App\Admin\Resources\ServerResource\Pages\EditServer::class,explode('@',$route->getActionName())[0],'Native generic editor route unchanged');
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
$pricingNoticeScopes();
echo "Installed paired native checkout, held status, service/batch/invoice DTO and foreign-owner refusal passed.\n";$nativeSuccessful=true;
