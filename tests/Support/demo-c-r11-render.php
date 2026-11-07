<?php
// Offline screenshots of real Blade and native Filament actions; no application/provider access.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$mode=$argv[2]??(getenv('DOMAINS_RENDER_STATE')?:'terms');
require dirname(__DIR__).($mode==='monitoring'?'/test_installed_demo_c_monitoring-test.php':'/test_installed_demo_c_terms-test.php');
$nativeSuccessful=false;
$out=$argv[1]??'';expect(is_dir($out),'Output directory required');
$skin=$argv[3]??(getenv('DOMAINS_RENDER_STAFF_SKIN')?:'');
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel=Filament\Facades\Filament::getCurrentPanel();
$panel->viteTheme([])->theme(new Illuminate\Support\HtmlString(''));
if(is_file($skin)){$css.=file_get_contents($skin);}
$staffRender=static function($test,string $name)use($out,$css):void{
    $request=Illuminate\Http\Request::create('http://domains-tests.invalid/','GET');
    $request->setLaravelSession(app('session')->driver());
    $request->setRouteResolver(fn()=>app('router')->getRoutes()->getByName('home'));
    app()->instance('request',$request);Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
    $partial=$test->effects['partials']['action-modals.0']??'';
    if($name==='complimentary'){
        // Alpine normally hydrates this native value; static screenshots have no JS runtime.
        $years=$test->get('mountedActions')[0]['data']['years'];
        $partial=preg_replace('~(<option\s+value="'.preg_quote((string)$years,'~').'")~','$1 selected',$partial);
    }
    $html=Illuminate\Support\Facades\Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',[
        'page'=>$test->instance(),'body'=>$test->html().$partial]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>|<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);
    $html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=str_replace('class="fi-modal fi-absolute-positioning-context"','class="fi-modal fi-absolute-positioning-context fi-modal-open"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.view('domains::partials.staff-styles')->render().'</head>',$html);
    file_put_contents($out.'/'.$name.'.html',$html);
};
if($mode==='monitoring'){
    $staffRender(Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\ViewWatch::class,['record'=>$watch->id]),'monitoring');
    $staffRender(Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource\Pages\MonitoringSettings::class),'settings');
}else{
    $probe->setValue(null,[Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue::class=>new Paymenter\Extensions\Servers\Domains\Catalogue\StaffCatalogue($ports,$clock),Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities::class=>new Paymenter\Extensions\Servers\Domains\Catalogue\Capabilities($ports,$clock,static fn()=>null)]);
    Illuminate\Support\Facades\Auth::login($actor);
    $list=Livewire\Livewire::actingAs($actor)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell::class);
    $key=$list->instance()->getTableRecordKey($row);
    $list->mountTableBulkAction('edit',[$key])->call('$refresh');
    expect(count($list->instance()->mountedActions)===1,'Real terms action is mounted');
    $staffRender($list,'terms');
    $product=$storefront->offer('com','register',1)['product'];
    // Native theme Vite uses a local hot URL while rendering only; no server or asset request.
    file_put_contents($fixture.'/public/hot','http://localhost:5173');
    session()->put('currency','USD');config(['settings.default_currency'=>'USD']);
    $cart=commerceCart($actor);commerceAdd($cart,$product,placeholderComFixtureName('renewal-placeholder.com'));
    commerceCheckout($actor,$cart);
    $d=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain',placeholderComFixtureName('renewal-placeholder.com'))->sole();
    Illuminate\Support\Facades\DB::table('services')->where('id',$d->service_id)->update(['status'=>'active']);
    $d->update(['status'=>'active','billing_managed'=>true,'authority_generation'=>1,'provider_expires_at'=>$now->modify('+1 year')]);
    app()->instance(Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::class,new Paymenter\Extensions\Servers\Domains\Services\RenewalCycles($bindings,$clock));
    // Assert genuine backend eligibility before mounting; no mocked action or invented term.
    expect(array_keys(app(Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::class)->complimentaryOptions($d->id,$actor)['terms'])===[1],'Complimentary fixture retains one evidenced year');
    $resource=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::class;
    $panel->resources([$resource,Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::class]);
    Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($resource,$panel){$resource::registerRoutes($panel);Paymenter\Extensions\Servers\Domains\Admin\Resources\WatchResource::registerRoutes($panel);});
    app('router')->getRoutes()->refreshNameLookups();
    $gift=Livewire\Livewire::actingAs($actor)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$d->id])->mountAction('complimentary_renewal')->call('$refresh');
    expect(count($gift->instance()->mountedActions)===1,'Real complimentary action is mounted');
    $staffRender($gift,'complimentary');
    // The same customer DTO test is restricted to the retained one-year choice for this witness.
    $domain=new Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain(name:'renewal-placeholder.com',status:'Active',tone:'ok',expiry:'2027-11-20',renewal:'On',renewalPrice:'$14.00',actions:['show'=>'/domains/1'],progress:[],setup:[],transfer:[],management:[],testEnvironment:false,renewNow:['url'=>'/domains/1/renew','terms'=>[1]]);
    $renew=view('domains::partials.owner-renew',['domain'=>$domain,'summary'=>null,'support'=>null,'keys'=>['renew'=>str_repeat('a',64)],'renewalChoices'=>$choices])->render();
    $customerCss=view('domains::partials.styles')->render();
    $search=storefrontData();$search['results']=[$search['results'][0]];$search['results'][0]['term']=3;$search['results'][0]['price']='$30.00';
    $searchHtml=view('domains::search',$search)->render();
    $dom=new DOMDocument;@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$searchHtml);
    $searchBody=$dom->saveHTML((new DOMXPath($dom))->query('//div[@class="domains"]')->item(0));
    foreach(['renew'=>$renew,'search'=>$searchBody]as$name=>$body){
        $html='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main class="domains">'.$customerCss.$body.'</main></body></html>';
        file_put_contents($out.'/'.$name.'.html',$html);
    }
}
$nativeSuccessful=true;echo "Demo C R11 local states rendered.\n";
