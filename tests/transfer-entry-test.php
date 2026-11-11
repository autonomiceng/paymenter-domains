<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Public entry links follow the displayed catalogue.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,StorefrontController};
use Paymenter\Extensions\Servers\Domains\Services\{Storefront,CheckoutEligibility};
// Exercise the installed customer adapter with independent local TEST deployment and transfer review premises.
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,
    'domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true,'domains.enom_writes.transfer'=>true]);
$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
foreach([\Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::class,\Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::class] as $type){
App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>$type::KEY],
    ['type'=>'string','encrypted'=>true,'value'=>json_encode(['fingerprint'=>$binding->fingerprint,...$type::protocol($binding)])]);
}
$store=new Storefront($sellers,$prices,$native,$bindings,activeFixtureEligibility($bindings,$clock),$clock);
app()->instance(Storefront::class,$store);
$controller=new OwnedDomainController($owners);$search=new StorefrontController($store);
$request=Request::create('/domains','GET');$request->setUserResolver(fn()=>$user);$request->setLaravelSession(app('request')->session());
$newTransfer('entry-placeholder.com',0,false,false);storefrontViews();
foreach([false,true,false] as $enabled){
    $ports=activeFixturePorts($native);$query=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['com'],'register');
    $ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($binding,'com',new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:true,longest:3,transfers:$enabled)),
        $ports->readOffers($query,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
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
// A TLD in a transfer link matches regardless of case; unsold and non-string endings stay refused.
$ports=activeFixturePorts($native);$query=new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding,['com'],'register');
$ports->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($binding,'com',new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell:true,longest:3,transfers:true)),
    $ports->readOffers($query,\Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision);
$transfer=function(array $query)use($store){$r=Request::create('/domains/transfer','GET',$query);$r->setLaravelSession(app('request')->session());
    return (new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedTransferController($store))->show($r)->getData();};
$lower=$transfer(['name'=>'example','tld'=>'com']);
same(null,$lower['error'],'Lowercase TLD link reaches a transfer check');
same('example.com',$lower['results'][0]['domain']??null,'Lowercase TLD link checks the named domain');
foreach(['COM','Com',' com '] as $tld){
    $data=$transfer(['name'=>'example','tld'=>$tld]);
    same([$lower['error'],$lower['preferred'],$lower['results']],[$data['error'],$data['preferred'],$data['results']],'TLD link takes the lowercase path: '.$tld);
}
same('idle',$lower['results'][0]['status'],'The entry page itself sends no registrar check');
foreach(['NET'=>'We don’t offer transfers for .net domains.','net'=>'We don’t offer transfers for .net domains.','C*M'=>'We don’t offer transfers for this ending.'] as $tld=>$message){
    $data=$transfer(['name'=>'example','tld'=>$tld]);
    expect(str_starts_with((string)$data['error'],$message),'Unsold TLD link is refused: '.$tld);
    same([],$data['results'],'Unsold TLD link checks no other name: '.$tld);
}
$data=$transfer(['name'=>'example','tld'=>['COM']]);
same(['com',null],[$data['preferred'],$data['error']],'Non-string TLD input is ignored, not read as an ending');
$nativeSuccessful=true;echo "Transfer entry links follow the public offer.\n";
