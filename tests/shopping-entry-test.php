<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureTransferRegistrars=['enom'];
require __DIR__.'/Support/owned.php';
require __DIR__.'/Support/web-visit.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Route};
use Paymenter\Extensions\Servers\Domains\Services\Storefront;
$store=activeFixtureStorefront($sellers,$prices,$native,$bindings,activeFixtureEligibility($bindings,$clock),$clock);
app()->instance(Storefront::class,$store);storefrontViews();
$route=Route::getRoutes()->getByName('domains.domains.transfer');
expect($route!==null,'A real transfer entry is registered');
expect(in_array('web',$route->gatherMiddleware(),true)&&!in_array('auth',$route->gatherMiddleware(),true),'Transfer entry supports guests in the native web session');
// Each visit is a real web-kernel request. A signed-in customer comes back only through the saved session cookie.
$visit=webVisit(...);
$path=static fn(string $url):string=>(string)parse_url($url,PHP_URL_PATH);
$back=static function(string $html)use($path):array {
    $links=[];foreach(storefrontDom($html)->query('//nav[@aria-label="Breadcrumb"]//a') as $link){$links[]=[$path($link->getAttribute('href')),trim($link->textContent)];}
    return $links;
};
$list=[$path(route('domains.domains.index')),'Domains'];
$response=$visit(null,'/domains/transfer');
same(200,$response->getStatusCode(),'Guest reaches the real transfer page without a login redirect');
$transfer=storefrontDom($response->getContent());
same(1,$transfer->query('//nav[@aria-label="Get a domain"]//a[@aria-current="true" and normalize-space()="Transfer"]')->length,'Transfer tab is current');
same([],$back($response->getContent()),'Guest transfer has no breadcrumb: the Register tab is the way to registration');
// A site that asks for verified email keeps the domain list from customers who have not verified yet.
config(['settings.mail_must_verify'=>true]);
$user->forceFill(['email_verified_at'=>now()])->save();$unverified=commerceUser();$unverified->forceFill(['email_verified_at'=>null])->save();
same(200,$visit($user,'/domains')->getStatusCode(),'A verified customer can open the domain list');
same(302,$visit($unverified,'/domains')->getStatusCode(),'An unverified customer is sent to verify before the domain list');
foreach(['verified'=>[$user,[$list]],'unverified'=>[$unverified,[]]] as $case=>[$who,$expected]){
    $response=$visit($who,'/domains/transfer');same(200,$response->getStatusCode(),'Signed-in '.$case.' customer reaches transfer');
    same($expected,$back($response->getContent()),'Transfer links back only to a domain list the '.$case.' customer can open');
    $response=$visit($who,'/domains/register');same(200,$response->getStatusCode(),'Signed-in '.$case.' customer reaches registration');
    same($case==='verified'?[$list]:[],$back($response->getContent()),'Registration links back only to a domain list the '.$case.' customer can open');
    $response=$visit($who,'/domains/pricing');same(200,$response->getStatusCode(),'Signed-in '.$case.' customer reaches prices');
    same($case==='verified'?[$list]:[],$back($response->getContent()),'Prices link back only to a domain list the '.$case.' customer can open');
}
config(['settings.mail_must_verify'=>false]);
same([$list],$back($visit($unverified,'/domains/transfer')->getContent()),'Without required verification every signed-in customer goes back to the domain list');
Auth::logout();
$data=storefrontData();$search=storefrontDom(view('domains::search',$data)->render());
same(1,$search->query('//nav[@aria-label="Get a domain"]//a[@aria-current="true" and normalize-space()="Register"]')->length,'Register tab is current');
$labels=[];foreach($search->query('//select[@name="term"]/option') as $option){$labels[]=$option->textContent;expect(str_contains($option->textContent,$option->getAttribute('data-total')),'Each year choice names its exact total');}
expect(count($labels)>=3,'Actual multi-year choices were rendered');
expect(str_contains($search->document->textContent,'first year'),'Search labels registration as the first year');
same(0,$transfer->query('//form[@role="search"]/@novalidate')->length,'Transfer keeps native required validation');
same(1,$transfer->query('//input[@name="name" and @required and @pattern]')->length,'Whitespace names fail native form validation');
foreach(['','   '] as $empty){$r=Request::create('/domains/transfer','GET',['name'=>$empty]);$r->setLaravelSession(app('request')->session());$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedTransferController($store))->show($r)->render();expect(str_contains($html,'Enter a domain name.'),'Explicit blank GET receives a visible validation reason');}
$nativeSuccessful=true;echo "Native guest transfer entry, tabs and exact year choices passed.\n";
