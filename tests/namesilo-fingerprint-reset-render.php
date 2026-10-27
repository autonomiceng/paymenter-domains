<?php
// Native offline account states; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-fingerprint-reset.php';
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
$out=$argv[1]??'';expect(is_dir($out),'Render directory exists');
$css=file_get_contents($fixture.'/vendor/filament/filament/dist/theme.css');
Filament::getCurrentPanel()->viteTheme([])->theme(new HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$render=static function($test,string $name)use($out,$css):void {
    $html=Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',
        ['page'=>$test->instance(),'body'=>$test->html().implode('',$test->effects['partials']??[])]);
    $html=preg_replace('~<script\b[^>]*>.*?</script>~si','',$html);$html=preg_replace('~<link\b[^>]*>~si','',$html);
    // Freeze the mounted modal open in the script-free native capture, as in staff-watch-render.
    $html=preg_replace('/x-cloak\s+x-show="isOpen"/','',$html);
    $html=str_replace('class="fi-modal fi-absolute-positioning-context"','class="fi-modal fi-absolute-positioning-context fi-modal-open"',$html);
    $html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
    $html=preg_replace('~src="https?://[^"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
    $html=str_replace('</head>','<style>'.$css.'</style>'.\Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
    expect(!str_contains($html,'synthetic-key'),'No credential in render');writeRenderArtifact($out.'/'.$name.'.html',$html);
};
$page=Livewire::actingAs($actor)->test(RegistrarAccount::class,['account'=>$server->id]);$render($page,'account');
$wire->email='NEW@example.com';$clock->instant=$clock->now()->modify('+2 seconds');
$page->mountAction('accept_namesilo_account')->assertActionMounted('accept_namesilo_account');$render($page,'matching');
$clock->instant=$clock->now()->modify('+2 seconds');$page->setActionData(['reason'=>'Verified intentional default contact change'])->callMountedAction()->assertHasNoActionErrors();$render($page,'accepted');
$wire->email='other@example.com';$clock->instant=$clock->now()->modify('+2 seconds');
$page=Livewire::actingAs($actor)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction('accept_namesilo_account');$render($page,'mismatch');
$page->setActionData(['reason'=>'Review mismatched contact refusal'])->callMountedAction();$render($page,'refused');
$wire->failed=true;$clock->instant=$clock->now()->modify('+2 seconds');
$page=Livewire::actingAs($actor)->test(RegistrarAccount::class,['account'=>$server->id])->mountAction('accept_namesilo_account');$render($page,'read-failed');
$actor->role->update(['permissions'=>['admin.domains.view']]);
$page=Livewire::actingAs($actor)->test(RegistrarAccount::class,['account'=>$server->id])->assertActionHidden('accept_namesilo_account');$render($page,'view-only');
echo "Seven offline account states rendered.\n";$nativeSuccessful=true;
