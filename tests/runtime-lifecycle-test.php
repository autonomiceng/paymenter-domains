<?php
// Fresh native application bootstrap acceptance. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/runtime-presenter-test.php';$nativeSuccessful=false;
// Export only this disposable fixture's synthetic database, then boot through native provider discovery.
$file=$fixture.'/database/runtime.sqlite';\Illuminate\Support\Facades\DB::statement("VACUUM INTO '".str_replace("'","''",$file)."'");
putenv('DB_DATABASE='.$file);$_ENV['DB_DATABASE']=$_SERVER['DB_DATABASE']=$file;
\Illuminate\Support\Facades\Facade::clearResolvedInstances();
$app=require $fixture.'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['audit.enabled'=>false,'settings.mail_disabled'=>true,'settings.default_currency'=>'USD','settings.theme'=>'default']);
\Illuminate\Support\Facades\Http::preventStrayRequests();
expect(app()->bound(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class),'Native provider discovers installed server runtime');
same(1,count(\Illuminate\Support\Facades\Event::getListeners('navigation')),'Fresh native provider has one navigation authority');
$router=app('router');$router->getRoutes()->refreshNameLookups();
$panel=\Filament\Facades\Filament::getPanel('admin');\Filament\Facades\Filament::setCurrentPanel($panel);$panel->viteTheme([])->theme(new \Illuminate\Support\HtmlString('<style>'.file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css').'</style>'));
\Qirolab\Theme\Theme::set('default','default');\Illuminate\Support\Facades\Auth::guard('web')->setUser(\App\Models\User::findOrFail($staff->id));
app(\App\Actions\Auth\Login::class)->execute(\App\Models\User::findOrFail($staff->id));app('session.store')->save();
$cookie=config('session.cookie');$sessionCookie=app('encrypter')->encrypt(\Illuminate\Cookie\CookieValuePrefix::create($cookie,app('encrypter')->getKey()).app('session.store')->getId(),false);
$kernel=app(\Illuminate\Contracts\Http\Kernel::class);
foreach(['servers'=>$server->id,'services'=>$service->id] as $kind=>$id){
    $resource=$kind==='servers'?\App\Admin\Resources\ServerResource::class:\App\Admin\Resources\ServiceResource::class;$routeName=$resource::getRouteBaseName($panel).'.edit';$route=$router->getRoutes()->getByName($routeName);expect($route!==null&&str_contains($route->getActionName(),'SecureEdit'),'Native bootstrap secures discovered editor route');
    $request=\Illuminate\Http\Request::create(route($routeName,['record'=>$id],false),'GET');
    $request->cookies->set($cookie,$sessionCookie);$response=$kernel->handle($request);if($response->getStatusCode()!==200){echo $kind.' HTTP '.$response->getStatusCode().' '.substr(strip_tags($response->getContent()),0,500).PHP_EOL;}same(200,$response->getStatusCode(),'Fresh installed native HTTP editor renders');
    expect(!str_contains($response->getContent(),'SECRET-NEVER-IN-SNAPSHOT'),'Fresh HTTP response excludes native saved secret');$kernel->terminate($request,$response);
}
echo "Fresh installed native provider, secure editor routes and HTTP responses passed.\n";$nativeSuccessful=true;
