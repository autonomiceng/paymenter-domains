<?php
// Native static panel fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\{Auth, Blade, DB};
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\{ListDomains, ViewDomain};
$out=$argv[1]??'';
expect(is_dir($out)&&is_writable($out),'Output directory argument required');
staffPanel(); [$held,$active,$long]=staffData(ordinaryCompleted:true);
DB::table('domains_observation_work')->insert(['server_id'=>$held->server_id,'account_fingerprint'=>$held->account_fingerprint,
    'domain_id'=>$held->id,'generation'=>$held->authority_generation,'due_at'=>time()]);
$panel=Filament::getCurrentPanel();
// Use this pinned dependency's compiled Filament stylesheet, without a Vite server or remote assets.
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
$loadingStyles=\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles();
$panel->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
foreach (['viewer'=>[Staff::VIEW], 'costs'=>[Staff::VIEW,Staff::COSTS,Staff::METADATA]] as $role=>$permissions) {
    $user=staffUser($permissions); Auth::setUser($user);
    foreach (['list'=>[ListDomains::class,[]], 'view'=>[ViewDomain::class,['record'=>$held->id]],
        'long'=>[ViewDomain::class,['record'=>$long->id]], 'active'=>[ViewDomain::class,['record'=>$active->id]]] as $name=>[$class,$params]) {
        $test=Livewire::actingAs($user)->test($class,$params)->assertSuccessful();
        $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',
            ['page'=>$test->instance(),'body'=>$test->html()]);
        // Static visual capture only: component interactions are covered by the native test suite.
        $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);
        $html=preg_replace('~<link\b[^>]*>~si','',$html);
        // Freeze the native layout's Alpine initial main-container style for static captures.
        $html=str_replace('x-cloak="-lg"','',$html);
        $html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
        $avatar='data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/><text x="20" y="26" text-anchor="middle" font-family="sans-serif" font-size="16" fill="white">SS</text></svg>');
        $html=preg_replace('~src="https?://[^\"]+"~','src="'.$avatar.'"',$html);
        $html=str_replace('</head>','<style>'.$css.'</style>'.$loadingStyles.'</head>',$html);
        expect(!str_contains($html,'PRIVATE-'),'Synthetic secret must not enter the rendered fixture');
        file_put_contents($out.'/'.$name.'-'.$role.'.html',$html);
    }
}
echo "Native staff list/view fixtures rendered.\n";
$nativeSuccessful=true;
