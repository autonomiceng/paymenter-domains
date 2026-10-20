<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Render actual Blade with synthetic presentation data.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Services\WatchTransferStrings;
require dirname(__DIR__).'/Domains/routes/owned.php';storefrontViews();$out=$argv[1];expect(is_dir($out),'Existing capture output');$strings=[];
foreach(['help','eligible','saving','owner-confirmation','owner-error','unavailable','changed']as$case){
    $data=['id'=>1,'name'=>'example.com','revision'=>'1','state'=>in_array($case,['saving','owner-confirmation','owner-error'],true)?'eligible':$case,
        'canCheck'=>true,'price'=>'$11.00','renew'=>'$14.00','saving'=>$case==='saving'?'$9.00':null,'verifiedRenew'=>$case==='saving'?'$20.00':null,'ownerError'=>$case==='owner-error','strings'=>WatchTransferStrings::all(),'key'=>str_repeat('a',64)];
    $html=view('domains::watch-transfer',$data)->render();if($case==='owner-confirmation'){$html=str_replace('name="authority" value="1" required ','name="authority" value="1" required checked ',$html);}
    file_put_contents($out.'/'.$case.'.html',$html);$strings[$case]=trim(preg_replace('/\s+/',' ',strip_tags(preg_replace('~<style\b[^>]*>.*?</style>~si','',$html))));
}
file_put_contents($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));$nativeSuccessful=true;echo "All comparison states rendered using actual Blade.\n";
