<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Owner help popovers and the logged-in help page.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/../Domains/routes/owned.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController, OwnerHelpController};
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
use Paymenter\Extensions\Servers\Domains\Services\OwnerHelp;
storefrontViews();
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS],str_repeat('a',64)));

// The help page sits behind the same login as the owner pages.
$route=Route::getRoutes()->getByName('domains.help');
same('domains/help',$route?->uri(),'Help page route');
same(Route::getRoutes()->getByName('domains.domains.show')->gatherMiddleware(),$route->gatherMiddleware(),'Help page uses the owner page middleware');
$guest=app(Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('/domains/help','GET'));
same(302,$guest->getStatusCode(),'Guests are sent to log in');
expect(str_contains((string)$guest->headers->get('Location'),'login'),'Guest redirect goes to login');

// One ## heading per stable anchor, in order, and no em dashes.
$markdown=file_get_contents(OwnerHelp::path());
preg_match_all('/^## (.+)$/m',$markdown,$headings);
same(array_values(OwnerHelp::ANCHORS),$headings[1],'Help doc headings match the anchors');
same(['nameservers','glue-records','dns-records','dnssec','transfer-lock','transfer-code','privacy','contacts','auto-renew'],array_keys(OwnerHelp::ANCHORS),'Stable anchors');
expect(!str_contains($markdown,'—'),'Help doc has no em dash');
same([],array_diff(array_unique(OwnerHelp::SECTIONS),array_keys(OwnerHelp::SUMMARIES)),'Every section anchor has a popover line');

// Every anchor is rendered with its id, and the contents list links to each one.
$dom=storefrontDom((new OwnerHelpController)->show()->render());
foreach (OwnerHelp::ANCHORS as $id => $title) {
    same([$title],array_map(fn($n)=>$n->textContent,iterator_to_array($dom->query('//h2[@id="'.$id.'"]'))),'Help page renders anchor '.$id);
    same(1,$dom->query('//nav//a[@href="#'.$id.'"]')->length,'Contents links to '.$id);
}
same(0,$dom->query('//article//h1')->length,'The page has one title');

// Markdown HTML is escaped and unsafe links are dropped.
$html=OwnerHelp::html("## Nameservers\n\n<script>alert(1)</script>\n\nText <b>bold</b> [x](javascript:alert(1))\n");
expect(!str_contains($html,'<script>')&&!str_contains($html,'<b>')&&str_contains($html,'&lt;script&gt;')&&str_contains($html,'&lt;b&gt;'),'Raw HTML is escaped');
expect(!str_contains($html,'href="javascript'),'Unsafe links are dropped');
same(1,storefrontDom($html)->query('//h2[@id="nameservers"]')->length,'Escaped input keeps the anchor id');

// Popovers: a real button with a name that controls its text, ending in a link to an existing anchor.
$section=static fn(string $label,array $extra=[])=>['label'=>$label,'url'=>'/domains/1/manage/x','actions'=>[],...$extra];
$management=['nameservers'=>$section('Nameservers',['max'=>12,'defaults'=>false,'current'=>null]),'lock'=>$section('Transfer lock'),'auth_code_email'=>$section('Transfer code',['recipient'=>'domain owner']),
    'privacy'=>$section('Domain privacy',['free'=>true,'price'=>null,'actions'=>['privacy_enable'=>'/e']]),'glue'=>$section('Glue records',['records'=>[],'recordCheckHeld'=>false,'actions'=>['glue_add'=>'/a']]),'ds'=>$section('DNSSEC',['records'=>[],'recordCheckHeld'=>false,'actions'=>['ds_add'=>'/d']])];
$domain=new OwnedDomain('example.com','Active','ok','2027-01-15','On','$14.00',['show'=>'/domains/1'],[],
    ['contacts'=>false,'required'=>false,'nameservers'=>true,'privacy'=>false,'defaults'=>true,'maxNameservers'=>12],[],$management,false,autoRenew:['url'=>'/domains/1/auto-renew','enabled'=>true]);
$dom=storefrontDom(view('domains::show',['domain'=>$domain])->render());
same(1,$dom->query('//a[normalize-space()="Domain help"][@href="'.route('domains.help').'"]')->length,'Manage page header links to Domain help once');
$buttons=$dom->query('//*[@data-help]/button');
same(count($management)+1,$buttons->length,'Each section and auto-renew have one help button');
foreach ($buttons as $button) {
    same(['button','false'],[$button->getAttribute('type'),$button->getAttribute('aria-expanded')],'Help trigger is a collapsed button');
    expect(str_starts_with($button->getAttribute('aria-label'),'About '),'Help trigger has an accessible name');
    $pop=$dom->query('//*[@id="'.$button->getAttribute('aria-controls').'"]')->item(0);
    expect($pop!==null&&!$pop->parentNode->hasAttribute('data-open'),'Help trigger controls its text, closed at first');
    $link=$dom->query('.//a[starts-with(normalize-space(),"Learn more")]',$pop)->item(0);
    [$path,$anchor]=explode('#',(string)$link?->getAttribute('href'))+[1=>''];
    same(route('domains.help'),$path,'Learn more opens the help page');
    expect(array_key_exists($anchor,OwnerHelp::ANCHORS),'Learn more points to an existing anchor: '.$anchor);
}
foreach (array_keys($management) as $cap) { same(1,$dom->query('//section[@id="'.$cap.'"]//*[@data-help]/button[@aria-controls="help-'.$cap.'"]')->length,'Help button next to the title: '.$cap); }
$nativeSuccessful=true;echo "Owner help checks passed.\n";
