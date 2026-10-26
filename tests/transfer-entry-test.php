<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Public entry links follow the displayed catalogue.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,StorefrontController};
use Paymenter\Extensions\Servers\Domains\Services\{Storefront,CheckoutEligibility};
$store=new Storefront($sellers,$prices,$native,$bindings,new CheckoutEligibility($bindings,$clock),$clock);
app()->instance(Storefront::class,$store);
$controller=new OwnedDomainController($owners);$search=new StorefrontController($store);
$request=Request::create('/domains','GET');$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('request')->session());
$newTransfer('entry-placeholder.com',0,false,false);storefrontViews();
foreach([false,true,false] as $enabled){
    $tlds[0]->update(['transfer_enabled'=>$enabled]);$native->publish($tlds[0]->id,$clock->now());
    $rows=$store->endings();same($enabled,$rows[0]['transfer']!==null,'Displayed transfer offer follows publication');
    $pricing=storefrontDom(view('domains::pricing',['rows'=>$rows,'guest'=>false,'testEnvironment'=>false])->render());
    same($enabled?$rows[0]['transfer']:'Not offered',trim($pricing->query('//td[@data-label="Transfer"]')->item(0)->textContent),'Price list is the offer source');
    foreach(['list'=>$controller->index($request)->render(),'search'=>$search->search($request)->render()] as $page=>$html){
        same($enabled?1:0,storefrontDom($html)->query('//a[@href="'.route('domains.domains.transfer').'"]')->length,'Transfer entry follows price list: '.$page);
    }
}
foreach([[],[['transfer'=>null]], [['transfer'=>null],['transfer'=>'$0.00']]] as $offers){
    $data=storefrontData();$data['endings']=array_map(fn($row)=>[...$data['endings'][0],...$row],$offers);
    $expected=count($offers)===2?1:0;
    same($expected,storefrontDom(view('domains::search',$data)->render())->query('//a[@href="'.route('domains.domains.transfer').'"]')->length,'Any offered TLD, including a free transfer, enables the search link');
    $empty=storefrontDom(view('domains::index',['domains'=>new \Illuminate\Pagination\LengthAwarePaginator([],0,25),'transfersOffered'=>$expected===1])->render());
    same($expected,$empty->query('//a[@href="'.route('domains.domains.transfer').'"]')->length,'Empty list has only its offered transfer entry');
}
$nativeSuccessful=true;echo "Transfer entry links follow the public offer.\n";
