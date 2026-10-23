<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/staff-tlds.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$entry=new Paymenter\Extensions\Servers\Domains\Domains;$entry->installed();extract(commerceFixture(true,static fn()=>null));$entry->boot();tldPanel();
$orphan=Paymenter\Extensions\Servers\Domains\Models\Domain::create(['user_id'=>987654,'service_id'=>987654,'domain'=>'missing-native.com','sld'=>'missing-native','tld'=>'com','server_id'=>$tlds[0]->server_id,'registrar_key'=>'enom','account_fingerprint'=>$tlds[0]->account_fingerprint,'status'=>'registration_pending']);
Paymenter\Extensions\Servers\Domains\Services\NativeIdentityDiscrepancies::inspect($orphan,now()->getTimestamp());
$staff=staffUser(array_keys(Staff::permissions()));$panel=Filament::getCurrentPanel();
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$test=Livewire::actingAs($staff)->test(RegistrarSetup::class)->assertSuccessful();
$test->assertSee('Adoption readiness')->assertSee('Off. Staff deleting a customer while that customer is checking out can remove the new domain’s Paymenter order and service. This can happen now, with adoption off. Affected domains appear under Missing Paymenter records. Adoption stays off until a later release closes this gap. Before any activation, let in-progress customer, order and service edits finish, then restart web and queue workers. Direct database edits bypass these checks.');
$test->assertSee('Missing Paymenter records')->assertSee('Customer, order or service missing')->assertSee('missing-native.com')->assertSee('The domain and its name stay reserved until a later release adds a resolution step.')->assertSee('Do not restore deleted records manually.');
$out=$argv[1]??null;
if($out!==null){
    expect(is_dir($out)&&is_writable($out),'Output directory required');
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    expect(!str_contains($html,'account-enom')&&!str_contains($html,'account-namesilo'),'No credentials in readiness rendering');
    file_put_contents($out.'/staff-setup.html',$html);
}
echo "Native staff adoption-off, drain and worker-restart readiness fact passed.\n";$nativeSuccessful=true;
