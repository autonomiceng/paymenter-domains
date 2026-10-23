<?php
// Native Filament rendering from installed bytes. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/render-artifacts.php';
use Paymenter\Extensions\Servers\Domains\Admin\Pages\ActivationStatus;
expect(is_dir($out),'Activation output directory exists');
$css=file_get_contents(base_path('vendor/filament/filament/dist/theme.css'));
$panel->viteTheme([])->theme(new Illuminate\Support\HtmlString('<link rel="stylesheet" href="fixture.css">'))->brandName('Paymenter');
$test=Livewire\Livewire::actingAs($staff)->test(ActivationStatus::class)->assertSuccessful();
$html=Illuminate\Support\Facades\Blade::render('<x-filament-panels::layout :livewire="$page">{!! $body !!}</x-filament-panels::layout>',['page'=>$test->instance(),'body'=>$test->html()]);
$html=preg_replace('~<script\b[^>]*>.*?</script>|<link\b[^>]*>~si','',$html);
$html=str_replace('x-cloak="-lg"','',$html);$html=str_replace('class="fi-main-ctn"','style="display:flex;opacity:1" class="fi-main-ctn"',$html);
$html=preg_replace('~src="https?://[^\"]+"~','src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#64748b"/></svg>').'"',$html);
$html=str_replace('</head>','<style>'.$css.'</style>'.Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles().'</head>',$html);
writeRenderArtifact($out.'/activation.html',$html);
