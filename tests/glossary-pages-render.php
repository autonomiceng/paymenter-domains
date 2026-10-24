<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Renders the client help page and the order pages from synthetic DTOs, dark and light; no public I/O.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController,OwnerHelpController};
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
storefrontViews();$out=$argv[1]??'';expect(is_dir($out),'Output directory required');
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS],str_repeat('a',64)));
$data=storefrontData();
$owned=static fn(array $setup,array $transfer)=>new OwnedDomain('example.com','In progress','info',null,'',null,['show'=>'/domains/1','setupSave'=>'/domains/1/setup','codeSave'=>'/domains/1/code','contactsSave'=>'/domains/1/contacts'],[],
    ['contacts'=>false,'required'=>true,'nameservers'=>true,'privacy'=>true,'defaults'=>true,'maxNameservers'=>12,...$setup],$transfer,[],false);
$line=static fn(?string $price)=>view('domains::checkout-line',['domain'=>['name'=>'example.com','action'=>'Registration','years'=>1,'renewalPrice'=>$price,'editUrl'=>'/cart/1']])->render();
$pages=[
    'help'=>(new OwnerHelpController)->show()->render(),
    'search'=>view('domains::search',$data)->render(),
    'pricing'=>view('domains::pricing',[...$data,'rows'=>$data['endings']])->render(),
    'transfer'=>view('domains::transfer',[...$data,'results'=>[]])->render(),
    'checkout-line'=>'<html><head></head><body>'.$line('$14.00').$line(null).'</body></html>',
    'setup'=>view('domains::setup',['domain'=>$owned([],[])])->render(),
    'setup-held'=>view('domains::setup',['domain'=>$owned(['required'=>false],[])])->render(),
    'auth-code'=>view('domains::code',['domain'=>$owned(['required'=>false],['open'=>true,'received'=>true,'paid'=>true,'deadline'=>'Nov 17, 2026, 12:00 UTC','held'=>false,'unchecked'=>true,'phase'=>'pending'])])->render(),
];
// Paymenter default theme colours (themes/default/views/layouts/colors.blade.php), so both modes read as they do in the client area.
$colors=':root{--color-primary:229 100% 64%;--color-neutral:220 25% 85%;--color-base:0 0% 0%;--color-muted:0 0% 40%;--color-success:142 71% 45%;--color-error:0 75% 60%;--color-warning:25 95% 53%;--color-info:210 100% 60%;--color-background:100 100% 100%;--color-background-secondary:0 0% 97%}'
    .'.dark{--color-neutral:0 0% 17%;--color-base:100 100% 100%;--color-muted:220 0% 53%;--color-background:240 18% 9%;--color-background-secondary:240 13% 11%}';
foreach($pages as $name=>$html){
    expect(!str_contains($html,'—'),$name.' has no em dash');
    foreach(['dark','light']as$mode){
        $doc=preg_replace('/<html\b[^>]*>/','<html lang="en" class="'.$mode.'">',str_replace('</head>','<style>'.$colors.'body{margin:0;padding:32px;background:hsl(var(--color-background));color:hsl(var(--color-base));font:15px/1.5 system-ui}</style></head>',$html),1);
        writeRenderArtifact($out.'/'.$name.'-'.$mode.'.html',$doc);
    }
}
$nativeSuccessful=true;echo "Help and order pages rendered dark and light.\n";
