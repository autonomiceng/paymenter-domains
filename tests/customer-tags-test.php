<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Demo and test-mode domains are tagged wherever a client page shows their name.
declare(strict_types=1);
require __DIR__.'/Support/owned.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary;
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings;
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
$controller=new OwnedDomainController($owners);$session=app('request')->session();
$request=static function()use($user,$session){$r=Request::create('/domains','GET');$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);return $r;};
// Each text node that is exactly the name must be followed by the expected tag, or by no tag for a real domain.
// The breadcrumb's last step is the page's own name, and the heading right below it carries the tag, so it is skipped.
$tags=static function(string $html,string $name):array{
    $x=storefrontDom($html);$found=[];
    foreach($x->query('//body//text()[normalize-space()="'.$name.'"][not(ancestor::nav[@aria-label="Breadcrumb"])]') as $text){
        $next=$x->query('following::*[1]',$text)->item(0);
        $found[]=$next instanceof DOMElement&&in_array('domains-demo-tag',explode(' ',$next->getAttribute('class')),true)?trim($x->query('text()',$next)->item(0)?->textContent??''):'none';
    }
    return $found;
};
$expectTags=static function(string $html,string $name,string $tag,string $page)use($tags):void{
    $found=$tags($html,$name);expect($found!==[],'Page shows the name: '.$page);
    same(array_fill(0,count($found),$tag),$found,'Every name on '.$page.' carries its tag: '.$tag);
};

$op=$newTransfer(placeholderComFixtureName('other-placeholder.com'),1,false,false);$d=$op->domain;storefrontViews();
foreach(['none'=>[false,false],'Test'=>[false,true],'Demo'=>[true,false],'Demo and test'=>[true,true]] as $case=>[$demo,$test]){
    $tag=$demo?'Demo':($test?'Test':'none');
    DB::table('domains_accounts')->update(['environment'=>$test?'test':'live']);
    $snapshot=$d->fresh()->snapshot??[];unset($snapshot['offline_demo']);$d->update(['snapshot'=>$demo?[...$snapshot,'offline_demo'=>true]:$snapshot]);$d=$d->fresh();
    same($demo,DemoData::is($d),'Fixture demo marker: '.$case);
    $dto=$owners->present($d);
    $pages=['list'=>$controller->index($request())->render(),'domain page'=>$controller->show($request(),$d->id)->render(),
        'service'=>view('domains::service',['domain'=>$dto])->render(),'invoice line'=>view('domains::invoice-line',['domain'=>$dto])->render()];
    $pages['transfer code']=$controller->code($request(),$d->id)->render();
    // Setup is offered while a held transfer waits for contact details and the registrar binding resolves (test accounts here).
    $was=$op->only(['state','safe_code']);$op->update(['state'=>'held','safe_code'=>'contact_details_required']);
    if(isset($owners->present($d->fresh())->actions['setup'])){$pages['setup']=$controller->setup($request(),$d->id)->render();}
    Paymenter\Extensions\Servers\Domains\Services\ContactSetup::recoveryWrite(fn()=>$op->update($was));
    if($test){expect(isset($pages['setup']),'Setup page is covered: '.$case);}
    foreach($pages as $page=>$html){$expectTags($html,$d->domain,$tag,$page.' ('.$case.')');}
    same(0,storefrontDom($pages['list'])->query('//*[@class="domains-testbar"]')->length,'The list uses tags, not a banner: '.$case);
    if($tag!=='none'){
        $node=storefrontDom($pages['domain page'])->query('//h1//span[contains(@class,"domains-demo-tag")]')->item(0);
        same($demo?DemoData::TOOLTIP:DemoData::testTooltip(),$node?->getAttribute('title'),'Tooltip explains the tag: '.$case);
    }
}
// Registrar test mode never stops billing: without the site's Test mode tag the Test tag never promises that nothing is charged.
same(DemoData::TEST_TOOLTIP,DemoData::testTooltip(),'Without the site Test mode tag, the Test tag says invoices are real');
// A site whose own Test mode tag says payments are test payments sets domains.test_payments; the Test tag then agrees.
config(['domains.test_payments'=>static fn()=>true]);
same(DemoData::TEST_PAYMENTS_TOOLTIP,DemoData::testTooltip(),'With site test payments, the Test tag says nothing real is charged');
config(['domains.test_payments'=>static fn()=>throw new RuntimeException('unreadable')]);
same(DemoData::TEST_TOOLTIP,DemoData::testTooltip(),'An unreadable site setting keeps invoices real');
config(['domains.test_payments'=>true]);$testPayments=DemoData::testTooltip();
$testSetting=new class {
    public int $calls=0;
    public function __invoke(): bool { $this->calls++;return true; }
};
config(['domains.test_payments'=>$testSetting]);
$callableTooltip=DemoData::testTooltip();
expect($testSetting->calls===1,'An invokable test-payments setting is resolved once');
expect($callableTooltip===$testPayments,'An invokable true setting has the same payment meaning as boolean true');
config(['domains.test_payments'=>null]);

$nativeSuccessful=true;echo "Customer Demo and Test tag checks passed.\n";
