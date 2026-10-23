<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Render actual Blade with synthetic presentation data, dark and light.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Services\WatchTransferStrings;
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();storefrontViews();$out=$argv[1];expect(is_dir($out),'Existing capture output');$strings=[];
// Paymenter default theme colours (themes/default/views/layouts/colors.blade.php), so both modes read as they do in the client area.
$colors=':root{--color-primary:229 100% 64%;--color-neutral:220 25% 85%;--color-base:0 0% 0%;--color-muted:0 0% 40%;--color-success:142 71% 45%;--color-error:0 75% 60%;--color-warning:25 95% 53%;--color-info:210 100% 60%;--color-background:100 100% 100%;--color-background-secondary:0 0% 97%}'
    .'.dark{--color-neutral:0 0% 17%;--color-base:100 100% 100%;--color-muted:220 0% 53%;--color-background:240 18% 9%;--color-background-secondary:240 13% 11%}';
foreach(['help','eligible','unavailable','changed','in_cart','no-offer']as$case){
    $data=['id'=>1,'name'=>'example.com','revision'=>'1','state'=>$case==='no-offer'?'help':$case,'canCheck'=>$case!=='no-offer',
        'price'=>'$11.00','renew'=>'$14.00','strings'=>WatchTransferStrings::all(),'key'=>str_repeat('a',64)];
    $html=view('domains::watch-transfer',$data)->render();expect(!str_contains($html,'—'),'No em dashes: '.$case);
    foreach(['dark','light']as$mode){writeRenderArtifact($out.'/transfer-'.$case.'-'.$mode.'.html',str_replace(['<html>','<head>','</head>'],['<html lang="en" class="'.$mode.'">','<head><meta charset="utf-8">','<style>'.$colors.'body{margin:0;padding:32px;background:hsl(var(--color-background));font:15px/1.5 system-ui}</style></head>'],$html));}
    $strings[$case]=trim(preg_replace('/\s+/',' ',strip_tags(preg_replace('~<style\b[^>]*>.*?</style>~si','',$html))));
}
writeRenderArtifact($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));$nativeSuccessful=true;echo "All transfer states rendered dark and light using actual Blade.\n";
