<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A value the page expects but does not have reads "Missing" with its cause and one fix.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/staff-panel.php';
require __DIR__.'/Support/facts-probe.php';
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Support\MissingEntry;
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
storefrontViews();

same('Missing',Missing::LABEL,'One word everywhere');
foreach ([null,'',[]] as $value) { expect(Missing::is($value),'Blank values are missing: '.json_encode($value)); }
foreach (['Off','No glue records',false,0,'0'] as $value) { expect(!Missing::is($value),'Values that say something are not missing: '.json_encode($value)); }

// Client partial: danger text with a decorative icon, then the cause, then the one fix.
$text=static fn(DOMXPath $dom,string $query)=>array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query($query)));
$full=storefrontDom(view('domains::partials.missing',['cause'=>'Shows when the transfer completes','fix'=>['label'=>'Renew now','url'=>'/domains/1/renew']])->render());
same(['Missing'],$text($full,'//*[@class="domains-missing"]'),'The value reads Missing');
same('true',$full->query('//*[@class="domains-missing"]/svg')->item(0)?->getAttribute('aria-hidden'),'The warning icon is decorative');
same(['Shows when the transfer completes'],$text($full,'//*[@class="domains-missing__cause"]'),'The cause sits next to the value');
same(['Renew now'],$text($full,'//a[contains(@class,"domains-missing__fix")]'),'One fix next to it');
same('/domains/1/renew',$full->query('//a[contains(@class,"domains-missing__fix")]')->item(0)?->getAttribute('href'),'The fix links to its action');
$bare=storefrontDom(view('domains::partials.missing')->render());
same(['Missing'],$text($bare,'//body/*'),'Without a cause or fix only the value shows (the page notice holds them)');
$slot=view('domains::partials.missing',['fix'=>new HtmlString('<button type="submit" class="domains-btn">Check status</button>')])->render();
same(['Check status'],$text(storefrontDom($slot),'//button'),'The fix slot takes a button or form');
same(view('domains::partials.missing',['cause'=>'No lifecycle policy saved'])->render(),Missing::html('No lifecycle policy saved')->toHtml(),'Missing::html renders the partial');
expect(!preg_match('/unknown|not recorded|unavailable/i',strip_tags(Missing::html('x')->toHtml())),'No other word for a missing value');

// Staff entry: a blank state reads Missing in danger with the warning icon; the fix is a hint action and the cause sits below.
staffPanel();
$fix=Action::make('sync')->label('Sync now')->action(fn()=>null);
$facts=renderFacts([
    MissingEntry::apply(TextEntry::make('expires')->label('Expires')->state(null),$fix,'Shows when the transfer completes'),
    MissingEntry::apply(TextEntry::make('registrar')->label('Registrar')->state('Enom'),Action::make('edit')->label('Edit')->action(fn()=>null),'Never shown'),
    MissingEntry::apply(TextEntry::make('lock')->label('Transfer lock')->state('Off')),
    MissingEntry::apply(TextEntry::make('cost')->label('Cost')->state('USD 12.00'),color:'warning'),
]);
['expires'=>$expires,'registrar'=>$registrar,'lock'=>$lock,'cost'=>$cost]=$facts['entries'];
same('Missing',$expires->getState(),'Blank staff state reads Missing');
same('danger',$expires->getColor($expires->getState()),'Missing is danger text');
same(Missing::ICON,$expires->getIcon($expires->getState()),'Missing carries the warning icon');
same(['sync'],array_map(fn($a)=>$a->getName(),array_values($expires->getHintActions())),'The fix is the hint action');
foreach (['Missing','Sync now','Shows when the transfer completes'] as $needle) { expect(str_contains($facts['html'],$needle),'Rendered entry shows '.$needle); }
same('Enom',$registrar->getState(),'A present value is unchanged');
same([null,null,[]],[$registrar->getColor('Enom'),$registrar->getIcon('Enom'),array_values($registrar->getHintActions())],'A present value has no danger, icon or fix');
expect(!str_contains($facts['html'],'Never shown')&&!str_contains($facts['html'],'>Edit<'),'Cause and fix show only when the value is missing');
same(['Off',null],[$lock->getState(),$lock->getColor('Off')],'Off is a value, not Missing');
same('warning',$cost->getColor('USD 12.00'),'A present value keeps its own colour');
$nativeSuccessful=true;echo "Missing value checks passed.\n";
