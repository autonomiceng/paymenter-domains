<?php
// Installed native panel and engine-owned customer projections. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/runtime-presenter-test.php';$nativeSuccessful=false;require_once __DIR__.'/Support/staff-panel.php';require __DIR__.'/Support/storefront.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\{RegistrarAccount,RegistrarSetup};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains,ViewDomain};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\{ListOperations,ViewOperation};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,SettingsActions,Installation};
$out=$argv[1]??'';$shell=file_get_contents($argv[2]??'');expect(is_dir($out),'Output directory required');
$staff=staffUser([...array_keys(Staff::permissions()),'admin.servers.viewAny','admin.services.viewAny','admin.servers.update','admin.servers.view','admin.servers.create']);Auth::setUser($staff);
$panel=Filament::getCurrentPanel();$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$render=static function($test,string $name)use($out,$css){
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',
        ['page'=>$test->instance(),'body'=>$test->html().($test->effects['partials']['action-modals.0']??'')]);
    $state=$test->get('mountedActions');writeRenderArtifact($out.'/'.$name.'.state.json',json_encode($state));
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    expect(!str_contains($html,'account-enom')&&!str_contains($html,'account-namesilo'),'No credential in rendered HTML');writeRenderArtifact($out.'/'.$name.'.html',$html);
};
session()->forget(['filament.notifications','filament.claimed_notifications']);
$d=\Paymenter\Extensions\Servers\Domains\Models\Domain::firstOrFail();$op=$d->operations()->sole();$t=$tlds[1];foreach($tlds as $holding){app(\Paymenter\Extensions\Servers\Domains\Services\NativeServerGuard::class)->pause($holding->server_id);}
// Quarantine uses retained unknown costs, never a synthetic zero price.
foreach(\Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id',$t->server_id)->whereIn('action',['register','transfer'])->get() as $price){$price->update(['revision'=>$price->revision+1,'wholesale'=>null,'hold_reason'=>'price_unavailable','eligibility'=>[...$price->eligibility,'price_unavailable'=>true,'staff_label'=>'Price unavailable']]);}
foreach(['staff-domains'=>[ListDomains::class,[]],'staff-domain'=>[ViewDomain::class,['record'=>$d->id]],'staff-queue'=>[ListOperations::class,[]],
    'staff-request'=>[ViewOperation::class,['record'=>$op->id]],'staff-tlds'=>[ListTlds::class,[]],'staff-tld'=>[ViewTld::class,['record'=>$t->id]],'staff-setup'=>[RegistrarSetup::class,[]],'staff-account'=>[RegistrarAccount::class,['account'=>$t->server_id]]] as $name=>[$class,$params]){
    $render(Livewire::actingAs($staff)->test($class,$params)->assertSuccessful(),$name);
}
foreach([false,true] as $enabled){
    if(!$enabled){$bindings->setProcessing($t->server_id,true,static fn()=>null);} // Seed an already enabled synthetic account before the actual pause action.
    $action=\Filament\Actions\Testing\TestAction::make('processing_'.$t->server_id)->schemaComponent('account_'.$t->server_id,'content');
    $test=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$t->server_id])->mountAction($action)->assertActionMounted($action)->setActionData(['enabled'=>$enabled,'reason'=>'Review installed processing change']);
    $test->call('$refresh');$render($test,'staff-processing-'.($enabled?'enable':'disable'));
    if(!$enabled){$test->setActionData(['enabled'=>false,'confirmed'=>true,'reason'=>'Review installed processing change'])->callMountedAction()->assertRedirect(RegistrarAccount::getUrl(['account'=>$t->server_id]));expect(!\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$t->server_id)->value('processing_enabled'),'Native pause persisted');$render(Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$t->server_id]),'staff-processing-saved');}
    if($enabled){$test->setActionData(['enabled'=>true,'confirmed'=>true,'reason'=>'Review installed processing change'])->callMountedAction();$render($test,'staff-activation-refusal');}
}
$legacy=\App\Models\Server::create(['type'=>'server','name'=>'Legacy','extension'=>'Enom','enabled'=>false]);$setting=$legacy->settings()->create(['key'=>'operations_enabled','type'=>'boolean','value'=>false]);
\Illuminate\Support\Facades\DB::table('settings')->where('id',$setting->id)->update(['value'=>'1']);
$refusal=Livewire::actingAs($staff)->test(\App\Admin\Resources\ServerResource\Pages\CreateServer::class)->fillForm(['name'=>'Refused installation','extension'=>'Domains'])->call('create')->assertHasFormErrors(['extension']);
$render($refusal,'staff-install-refusal');
$setting->update(['value'=>false]);
// Actual engine DTOs from native checkout/ledger, both holders and held/pending registrations.
storefrontViews();Auth::setUser($user);$request=Illuminate\Http\Request::create('/domains');$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('session.store'));app()->instance('request',$request);
$controller=app(\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController::class);
$pages=['customer-list'=>$controller->index($request)->render()];
foreach(\Paymenter\Extensions\Servers\Domains\Models\Domain::orderBy('id')->get() as $i=>$domain){
    $pages['customer-held-'.$i]=$controller->show($request,$domain->id)->render();
    $domain->operations()->sole()->update(['state'=>'pending']);$pages['customer-pending-'.$i]=$controller->show($request,$domain->id)->render();
    $invoiceOp=$domain->operations()->sole();$dto=$presenter->invoice($user,$invoiceOp->invoice_id)[$invoiceOp->invoice_item_id];$pages['customer-invoice-'.$i]=view('domains::invoice-line',['domain'=>$dto])->render();
    $pages['customer-cart-edit-'.$i]=$cartEdits[$i]->render();
    $pages['customer-checkout-'.$i]=view('domains::checkout-line',['domain'=>$checkoutLines[$i]])->render();
}
$pages['customer-list-pending']=$controller->index($request)->render();
foreach($pages as $name=>$html){preg_match('#<body>(.*)</body>#s',$html,$body);$content=$body[1]??$html;$content=preg_replace('#<script\b[^>]*>.*?</script>#s','',$content);writeRenderArtifact($out.'/'.$name.'.html',str_replace('@@SLOT@@',$content,$shell));}
$strings=[];foreach(glob($out.'/*.html') as $file){$strings[basename($file)]=trim(preg_replace('/\s+/u',' ',strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',file_get_contents($file)))));}
writeRenderArtifact($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Installed staff discovery, processing/refusal and paired customer pages rendered.\n";$nativeSuccessful=true;
