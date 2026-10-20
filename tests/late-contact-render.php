<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Installed offline panel, real owner POST and presenter.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
require __DIR__.'/Support/installed.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery,OwnerSetup,DomainManagement};
installedPanel();
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$out=$argv[1];$shell=file_get_contents($argv[2]);$controller=new OwnedDomainController($owners);
$actions=new OwnerActionController($owners,new OwnerSetup($clock,$ports),$inputs,new DomainManagement($bindings,$clock),$ports);
$user->properties()->where('key','phone')->update(['value'=>'']);$user->unsetRelation('properties');
$late=$newTransfer('contact-fix.com');
$missing=$newTransfer('no-saved-code.com',0,true,false);
ContactRecovery::hold($late->id);ContactRecovery::hold($missing->id);
$session=app('session')->driver();$session->start();
$request=static function()use($user,$session){$r=Illuminate\Http\Request::create('/domains','GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);return $r;};
$clock->instant=$now->modify('+30 days');Carbon\Carbon::setTestNow($clock->instant);Carbon\CarbonImmutable::setTestNow($clock->instant);
$emit=static function($op,string $name)use($out,$shell,$controller,$request):void{
    storefrontViews();app('view')->getFinder()->flush();$html=$controller->code($request(),$op->domain_id)->render();
    preg_match('#<body>(.*)</body>#s',$html,$body);file_put_contents($out.'/'.$name.'.html',str_replace('@@SLOT@@',$body[1]??$html,$shell));
};
$emit($late,'customer-contact-fix');$emit($missing,'customer-no-code');
$user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);$user->unsetRelation('properties');
$r=$request();$r->setMethod('POST');$r->request->set('_request_key',Paymenter\Extensions\Servers\Domains\Http\FormKeys::issue($r,'owner:'.$late->domain_id.':recovery'));
$actions->contacts($r,$late->domain_id);same('transfer_setup_review_required',$late->fresh()->safe_code,'Actual owner POST committed a staff-review hold');
same('held',$late->fresh()->state,'Late contact correction remains held');
$emit($late,'customer-contact-review');
$staff=staffUser(array_keys(Staff::permissions()));Auth::setUser($staff);
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
Filament::getCurrentPanel()->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$test=Livewire::actingAs($staff)->test(ViewOperation::class,['record'=>$late->id])->assertSee('Transfer setup needs review')->assertSee('Code deadline passed. Release is unavailable');
$html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
$html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
$html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
$html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
$html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
file_put_contents($out.'/staff-contact-review.html',$html);
$strings=[];foreach(glob($out.'/*.html')as$file){$strings[basename($file)]=trim(preg_replace('/\s+/u',' ',strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',file_get_contents($file)))));}
file_put_contents($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
same([],$state->calls,'Render and recovery have no provider calls');same(0,$state->reads,'Render and recovery have no provider reads');
echo "Installed staff gate and actual owner late contact pages rendered.\n";$nativeSuccessful=true;
