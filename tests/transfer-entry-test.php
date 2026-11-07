<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Public entry links follow the displayed catalogue.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,StorefrontController};
use Paymenter\Extensions\Servers\Domains\Services\{Storefront,CheckoutEligibility};
$store=new Storefront($sellers,$prices,$native,$bindings,activeFixtureEligibility($bindings,$clock),$clock);
app()->instance(Storefront::class,$store);
$controller=new OwnedDomainController($owners);$search=new StorefrontController($store);
$request=Request::create('/domains','GET');$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('request')->session());
$newTransfer('entry-placeholder.com',0,false,false);storefrontViews();
foreach([false,true,false] as $enabled){
    $tlds[0]->update(['transfer_enabled'=>$enabled]);activeFixtureRepublish($native,$tlds[0]->id,$clock->now());
    $rows=$store->endings();same($enabled,$rows[0]['transfer']!==null,'Displayed transfer offer follows publication');
    $pricing=storefrontDom(view('domains::pricing',['rows'=>$rows,'guest'=>false,'testEnvironment'=>false])->render());
    same($enabled?$rows[0]['transfer']:'Transfers off',trim($pricing->query('//td[@data-label="Transfer"]')->item(0)->textContent),'Price list is the offer source');
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
// Register and Transfer are two tabs at the top of both pages; each page marks its own.
$data=storefrontData();$data['endings']=[[...$data['endings'][0],'transfer'=>'$9.00']];
foreach(['search'=>['Register',view('domains::search',$data)],'transfer'=>['Transfer',view('domains::transfer',[...$data,'results'=>[]])]] as $page=>[$current,$view]){
    $tabs=storefrontDom($view->render())->query('//nav[@aria-label="Get a domain"]/a');
    same(['Register','Transfer'],array_map(fn($a)=>trim($a->textContent),iterator_to_array($tabs)),'Register and Transfer tabs on '.$page);
    same([$current],array_map(fn($a)=>trim($a->textContent),array_values(array_filter(iterator_to_array($tabs),fn($a)=>$a->getAttribute('aria-current')==='true'))),$page.' marks its own tab');
}
$nativeSuccessful=true;echo "Transfer entry links follow the public offer.\n";
