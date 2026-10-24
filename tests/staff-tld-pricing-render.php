<?php
// Static captures of TLDs & pricing and the TLD page: synced, sync failed, no registrar for new orders; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth,Blade,DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{ListTlds,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{ObservationSchema,StaffSettingsReview as Review};
$out=$argv[1]??'';expect(is_dir($out)&&is_writable($out),'Output directory required');
$f=commerceFixture();extract($f);tldPanel();ObservationSchema::install();Carbon\Carbon::setTestNow($now);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
[$enom,$silo]=$tlds;
// Several registrar accounts: Enom takes new orders, the NameSilo account is demo data.
DB::table('extensions')->where('id',$enom->server_id)->update(['name'=>'Enom TEST identity check']);
DB::table('extensions')->where('id',$silo->server_id)->update(['name'=>'OFFLINE DEMO NameSilo (synthetic)']);
DB::table('domains_accounts')->where('server_id',$silo->server_id)->update(['stable_identity'=>Illuminate\Support\Facades\Crypt::encryptString('beco-demo-namesilo')]);
Domain::create(['server_id'=>$enom->server_id,'registrar_key'=>'enom','account_fingerprint'=>$enom->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);
$permissions=[Staff::VIEW,Staff::COSTS,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);
$panel=Filament::getCurrentPanel();$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$render=static function(string $class,array $params,string $name)use($u,$out,$css):void {
    $test=Livewire::actingAs($u)->test($class,$params)->assertSuccessful();
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
    // Static visual capture only: interactions are covered by staff-tld-page-test.php.
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    expect(!str_contains($html,'account-enom')&&!str_contains($html,'beco-demo-namesilo'),'No synthetic account identity');
    expect(!str_contains($html,"\u{2014}"),'No em dashes in rendered page');
    writeRenderArtifact($out.'/'.$name.'.html',$html);
};
$render(ListTlds::class,[],'list-synced');$render(ViewTld::class,['record'=>$enom->id],'tld-synced');
DB::table('domains_observation_queues')->insert(['server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,
    'catalogue_hold'=>'catalogue_reader_unavailable','catalogue_audit'=>json_encode([['code'=>'catalogue_reader_unavailable','server_id'=>$enom->server_id,
        'account_fingerprint'=>$enom->account_fingerprint,'at'=>'2026-10-18T13:05:00+00:00']])]);
$render(ListTlds::class,[],'list-sync-failed');$render(ViewTld::class,['record'=>$enom->id],'tld-sync-failed');
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->delete();
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>null]);
$render(ListTlds::class,[],'list-no-registrar');$render(ViewTld::class,['record'=>$enom->id],'tld-no-registrar');
echo "TLDs & pricing list and TLD page captures rendered.\n";$nativeSuccessful=true;
