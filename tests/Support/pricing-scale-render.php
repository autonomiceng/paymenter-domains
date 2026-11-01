<?php
// Real Blade rendering requires Paymenter's view engine; all rows are synthetic and no provider is installed.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/storefront.php';
require dirname(__DIR__,2).'/Domains/routes/web.php';
storefrontViews();
$rows=storefrontData()['endings'];
foreach(range('a','z') as $a){foreach(range('a','z') as $b){$rows[]=[...$rows[0],'tld'=>'scale'.$a.$b];if(count($rows)===599){break 2;}}}
$rows=array_reverse($rows);
// Match the existing offline button harness's minimal shell; site/theme assets are deliberately absent.
$base='html{font-size:16px}*{box-sizing:border-box}body{margin:0;padding:16px;font-family:system-ui}button{font-family:inherit}';
writeRenderArtifact($argv[1].'/pricing-599.html',str_replace('</head>','<style>'.$base.'</style></head>',view('domains::pricing',['rows'=>$rows,'guest'=>true,'testEnvironment'=>false])->render()));
$summary=['text'=>'Activate the selected Live features.','help'=>'OFFLINE synthetic cost review.','missing'=>[],
    'costs'=>array_map(static fn($row)=>['tld'=>$row['tld'],'action'=>'register','term'=>1,'cost'=>'10.0000','price'=>'13.99',
        'renewCost'=>'10.0000','renewPrice'=>'13.99','note'=>null],$rows),
    'tlds'=>599,'counts'=>['register'=>599,'transfer'=>0,'renew'=>0],'range'=>['10.0000','10.0000'],'exceptions'=>[],'failures'=>[]];
writeRenderArtifact($argv[1].'/activation-599.html','<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.$base.'</style></head><body>'.view('domains::admin.live-activation',compact('summary'))->render().'</body></html>');
$nativeSuccessful=true;
