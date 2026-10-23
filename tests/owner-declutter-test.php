<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Owner management forms: nameserver prefill, confirm steps and customer copy.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/../Domains/routes/owned.php';
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains, OwnerRecordDraft};
storefrontViews();
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS],str_repeat('a',64)));
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$section=static fn(string $label,array $extra=[])=>['label'=>$label,'url'=>'/domains/1/manage/x','actions'=>[],...$extra];
$management=static fn(?array $current)=>[
    'nameservers'=>$section('Nameservers',['url'=>'/domains/1/manage/nameservers','max'=>12,'defaults'=>true,'current'=>$current,'defaultServers'=>OwnedDomains::DEFAULT_NAMESERVERS]),
    'lock'=>$section('Transfer lock',['url'=>'/domains/1/manage/lock']),
    'auth_code_email'=>$section('Transfer code',['url'=>'/domains/1/manage/auth_code_email','recipient'=>'domain owner']),
    'glue'=>$section('Glue records',['records'=>[['host'=>'ns1.example.com','ip'=>'192.0.2.53']],'actions'=>['glue_add'=>'/a','glue_update'=>'/u','glue_delete'=>'/d']]),
    'privacy'=>$section('Domain privacy',['free'=>true,'price'=>null,'actions'=>['privacy_enable'=>'/e','privacy_disable'=>'/x']]),
];
$dto=static fn(array $m)=>new OwnedDomain('example.com','Active','ok','2027-01-15','On','$14.00',['show'=>'/domains/1'],[],
    ['contacts'=>false,'required'=>false,'nameservers'=>true,'privacy'=>false,'defaults'=>true,'maxNameservers'=>12],[],$m,false);
$render=static fn(OwnedDomain $d)=>storefrontDom(view('domains::partials.owner-management',['domain'=>$d])->render());

// Known nameservers prefill the form; unknown ones leave it empty with a neutral placeholder, never a guess.
$dom=$render($dto($management(['ns1.host.example','ns2.host.example'])));
same("ns1.host.example\nns2.host.example",$dom->query('//textarea[@name="nameservers"]')->item(0)->textContent,'Known nameservers prefill the form');
same(['ns1.host.example','ns2.host.example'],array_map(fn($n)=>$n->textContent,iterator_to_array($dom->query('//dialog[@id="confirm-nameservers"]//div[dt="Current"]//span'))),'Confirm shows the current nameservers');
$dom=$render($dto($management(null)));$area=$dom->query('//textarea[@name="nameservers"]')->item(0);
same('',$area->textContent,'Unknown nameservers leave the form empty');
same("ns1.example.com\nns2.example.com",$area->getAttribute('placeholder'),'Neutral placeholder only');
same(1,$dom->query('//dialog[@id="confirm-nameservers"]//div[dt="Current"][@hidden]')->length,'Unknown current nameservers are not shown as a value');
// Defaults fill the textarea with script; without script the same button submits the server-side defaults path.
same(implode("\n",OwnedDomains::DEFAULT_NAMESERVERS),$dom->query('//form[@data-default-servers]')->item(0)?->getAttribute('data-default-servers'),'Default list ready to fill the textarea');
$fill=$dom->query('//button[@data-fill-defaults]')->item(0);
same(['submit','use_default','1'],[$fill->getAttribute('type'),$fill->getAttribute('name'),$fill->getAttribute('value')],'Use Beco nameservers falls back to the defaults path without script');
expect($fill->hasAttribute('formnovalidate')&&str_contains($fill->getAttribute('class'),'domains-btn--ghost'),'Defaults is a secondary button that skips the empty-field check');
same(1,$dom->query('//li[@id="nameservers"]//dialog[@id="edit-nameservers"]//button[contains(@class,"domains-btn--primary")][not(ancestor::dialog[@data-confirm-dialog])]')->length,'One primary Save button');
same(1,$dom->query('//input[@data-use-default][@disabled]')->length,'Defaults flag stays off until defaults are chosen');

// No acknowledgement checkboxes; risky actions get one closed confirm dialog, plain actions none.
same(0,$dom->query('//input[@type="checkbox"]')->length,'No checkbox inputs in owner management views');
foreach(['nameservers','lock','glue'] as $cap){same(1,$dom->query('//li[@id="'.$cap.'"]//form[@data-confirm]//dialog[@data-confirm-dialog][not(@open)]//button[@type="submit"][@name="confirmed"][@value="1"]')->length,'Risky action confirms in a dialog: '.$cap);}
same(1,$dom->query('//li[@id="auth_code_email"]//form[@data-confirm]//dialog[@data-confirm-dialog]')->length,'Auth code email confirms in a dialog');
same(1,$dom->query('//li[@id="privacy"]')->length,'Privacy row rendered');same(0,$dom->query('//li[@id="privacy"]//dialog')->length,'Privacy has no confirm step');
same(0,$dom->query('//form[@action="/a"]//dialog')->length,'Adding a glue record has no confirm step');
same(['Nameserver','ns1.example.com','IP address','192.0.2.53'],array_values(array_filter(array_map(fn($n)=>trim($n->textContent),iterator_to_array($dom->query('//dialog[@id="confirm-glue-0"]//dl/div/*'))))),'Glue delete names the concrete record');

// Without script, the server re-renders the confirm step open, keeping the submitted values.
$step=OwnerRecordDraft::apply($dto($management(['ns1.host.example','ns2.host.example'])),'nameservers',['nameservers'=>'','use_default'=>'1'],confirm:true);
$dom=$render($step);
same(implode("\n",OwnedDomains::DEFAULT_NAMESERVERS),$dom->query('//textarea[@name="nameservers"]')->item(0)->textContent,'Defaults confirm step shows the default list');
same(1,$dom->query('//input[@data-use-default][not(@disabled)]')->length,'Defaults confirm step keeps the defaults path');
same(1,$dom->query('//dialog[@id="confirm-nameservers"][@open]')->length,'Confirm step renders inline without script');
same('/domains/1?edit=nameservers#edit-nameservers',$dom->query('//dialog[@id="confirm-nameservers"][@open]//a[normalize-space()="Cancel"]')->item(0)?->getAttribute('href'),'Inline step cancels back to the form');same(1,$dom->query('//dialog[@id="edit-nameservers"][@open]')->length,'The editor stays open around the confirm step');
same(5,$dom->query('//dialog[@open]//div[dt="New"]//span')->length,'Confirm step lists the new nameservers');
$dom=$render(OwnerRecordDraft::apply($dto($management(null)),'glue_delete',['host'=>'ns1.example.com'],confirm:true));
same(1,$dom->query('//dialog[@id="confirm-glue-0"][@open]')->length,'Glue delete confirm step opens for the chosen record');

// Copy: one term for glue records in customer views and no em dashes in any customer or staff view.
$dash=static fn(string $text): bool => str_contains($text,'—')||stripos($text,'&mdash;')!==false||stripos($text,'&#8212;')!==false;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../Domains/resources/views',FilesystemIterator::SKIP_DOTS)) as $file) {
    $text=preg_replace('/\{\{--.*?--\}\}/s','',file_get_contents($file->getPathname()));
    expect(!$dash($text),'No em dash in view '.$file->getFilename());
    if (preg_match('~/(admin|staff)/~',$file->getPathname())) { continue; }
    expect(stripos($text,'child nameserver')===false,'No "child nameservers" in customer view '.$file->getFilename());
}
// String literals in runtime code feed staff tables, facts, notices and mail; comments are skipped.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../Domains',FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!str_ends_with($file->getFilename(),'.php')||str_ends_with($file->getFilename(),'.blade.php')) { continue; }
    foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
        if (is_array($token)&&in_array($token[0],[T_CONSTANT_ENCAPSED_STRING,T_ENCAPSED_AND_WHITESPACE,T_INLINE_HTML],true)) {
            expect(!$dash($token[1])&&stripos($token[1],'\u{2014}')===false,'No em dash in string at '.$file->getFilename().':'.$token[2]);
        }
    }
}
expect(!str_contains(json_encode(\Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings::all(),JSON_UNESCAPED_UNICODE),'—'),'Summary strings have no em dash');
$nativeSuccessful=true;echo "Owner declutter checks passed.\n";
