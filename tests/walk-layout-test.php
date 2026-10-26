<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real Blade layout fixtures and browser assertions.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
storefrontViews();
$data=storefrontData();
$data['endings'][0]['transfer']=null;
$pages=[];
foreach(['annual'=>[1],'terms'=>[1,3]] as $case=>$terms){
    $rows=[$data['endings'][0]];$rows[0]['terms']=$terms;
    $pages['pricing-'.$case]=view('domains::pricing',['rows'=>$rows,'guest'=>true,'testEnvironment'=>false])->render();
    $dom=storefrontDom($pages['pricing-'.$case]);
    same('Register',$dom->query('//tbody/tr/td')->item(0)->getAttribute('data-label'),'Registration cell has its mobile label');
    same('Not offered',trim($dom->query('//td[@data-label="Transfer"]')->item(0)->textContent),'Missing transfer offer remains explicit');
}
$domain=new OwnedDomain('example.com','Active','ok','2027-03-01','On','$14.00',[],
    [['label'=>'Nameservers','state'=>'Completed','date'=>'2026-10-20']],[],[],[],false,
    history:[['number'=>'INV-1042','status'=>'Paid','url'=>'/invoices/1042','date'=>'2026-10-21']]);
$pages['history']='<html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><div class="domains">'.view('domains::partials.styles')->render().view('domains::partials.activity',['domain'=>$domain])->render().'</div></body></html>';
$dom=storefrontDom($pages['history']);
same(2,$dom->query('//tbody/tr')->length,'Progress and renewal history both render');
if(isset($argv[1])){
    expect(is_dir($argv[1]),'Existing output directory required');
    $css='*{box-sizing:border-box}body{margin:0;padding:24px;background:#14141b;color:#eee;font:15px/1.5 system-ui}h1,h2,p{margin:0}.domains{--color-base:0 0% 95%;--color-muted:0 0% 60%;--color-neutral:0 0% 22%;--color-background:240 18% 9%;--color-background-secondary:240 13% 11%;--color-primary:229 100% 64%}';
    foreach($pages as $name=>$html){
        $html=preg_replace('#<script\b[^>]*>.*?</script>#s','',$html);
        $html=str_replace('</head>','<meta charset="utf-8"><style>'.$css.'</style></head>',$html);
        writeRenderArtifact($argv[1].'/'.$name.'.html',$html);
    }
}
$nativeSuccessful=true;echo "Walk layout Blade fixtures passed.\n";
