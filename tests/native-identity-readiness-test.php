<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/staff-tlds.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Blade,DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
$entry=new Paymenter\Extensions\Servers\Domains\Domains;$entry->installed();extract(commerceFixture(true,static fn()=>null));$entry->boot();tldPanel();
$orphan=Paymenter\Extensions\Servers\Domains\Models\Domain::create(['user_id'=>987654,'service_id'=>987654,'domain'=>'missing-native-placeholder.com','sld'=>'missing-native-placeholder','tld'=>'com','server_id'=>$tlds[0]->server_id,'registrar_key'=>'enom','account_fingerprint'=>$tlds[0]->account_fingerprint,'status'=>'registration_pending']);
Paymenter\Extensions\Servers\Domains\Services\NativeIdentityDiscrepancies::inspect($orphan,now()->getTimestamp());
$staff=staffUser(array_keys(Staff::permissions()));$panel=Filament::getCurrentPanel();
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$test=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$tlds[0]->server_id])->assertSuccessful();
$test->assertSee('System checks')->assertDontSee('Adoption readiness')->assertDontSee('Renewal safety');
// Missing records show on the affected domains (Under review), not on the account page.
$test->assertDontSee('Missing Paymenter records')->assertDontSee('missing-native-placeholder.com');
// Each system check appears once; site notes moved to docs/runtime.md appear nowhere.
$site=[...\Paymenter\Extensions\Servers\Domains\Services\NativePaidHooks::notices(),'Server delete notice'=>1,'Domain editor notice'=>1,'Service refusal notice'=>1];
$flat=$test->instance()->content(\Filament\Schemas\Schema::make($test->instance()))->getFlatComponents();
$count=fn(string $label)=>count(array_filter($flat,fn($entry)=>$entry instanceof \Filament\Infolists\Components\TextEntry && $entry->getLabel()===$label));
foreach(['Background worker','Payment events','Domain editor','Site setup'] as $label){same(1,$count($label),'System check appears once: '.$label);}
foreach(['Renewal safety','Adoption readiness','Credentials','Worker cadence','Worker recovery','Binding recovery','Native server settings',...array_keys($site)] as $label){
    same(0,$count($label),'Removed or renamed fact never appears: '.$label);
}
same('60, 30, 7, 1',\Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::schedule($tlds[0]->server_id)['Days before expiry'],'Default reminder display order');
$scheduleTable=DB::table('domains_reminder_schedules');
$scheduleTable->insert(['server_id'=>$tlds[0]->server_id,'account_fingerprint'=>$tlds[0]->account_fingerprint,'before_days'=>'[1,7,30,60]','after_days'=>'[7,1,3]','audit'=>'synthetic']);
$raw=$scheduleTable->where('server_id',$tlds[0]->server_id)->first();
$sorted=\Paymenter\Extensions\Servers\Domains\Admin\Support\ReminderFacts::schedule($tlds[0]->server_id);
same('60, 30, 7, 1',$sorted['Days before expiry'],'Saved reminders display descending');
same('1, 3, 7',$sorted['Days after expiry'],'Saved after reminders display ascending');
same((array)$raw,(array)$scheduleTable->where('server_id',$tlds[0]->server_id)->first(),'Display sorting never changes stored JSON');
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
