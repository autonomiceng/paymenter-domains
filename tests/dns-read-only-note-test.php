<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A Read-only DNS record says why it can't be changed and what to do.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
storefrontViews();
$support = 'https://example.test/support';
config(['settings.tickets_disabled' => true, 'domains.support_url' => $support]);
$supportLink = static fn (DOMXPath $dom) => $dom->query('//a[@href="'.$support.'"]')->length;
$records=[['name'=>'*','type'=>'A','address'=>'192.0.2.10','ttl'=>3600]];
$dom=storefrontDom(view('domains::partials.dns-inventory',['section'=>['inventory'=>$records,'actions'=>[]]])->render());
same(1,$supportLink($dom),'Read-only records link to support');
$dom=storefrontDom(view('domains::partials.dns-inventory',['section'=>['inventory'=>$records,'types'=>['A'],'actions'=>['dns'=>'/domains/1/dns']]])->render());
same([0,0],[$dom->query('//tr[@data-read-only]')->length,$supportLink($dom)],'Editable records need no read-only support link');
// Missing actions still render a read-only inventory without an undefined-key warning.
$dom=storefrontDom(view('domains::partials.dns-inventory',['section'=>['inventory'=>$records]])->render());
same(1,$supportLink($dom),'An inventory without edit actions links to support');
// A CAA sibling is always read-only while the A record stays editable.
$dom=storefrontDom(view('domains::partials.dns-inventory',['section'=>['inventory'=>$records,'types'=>['A','CAA'],'actions'=>['dns'=>'/domains/1/dns'],
    'caa'=>[['name'=>'@','value'=>'0 issue "example.test"','ttl'=>3600]]]])->render());
same(0.0,$dom->evaluate('count(//tr[td[@data-label="Type"]="A"][@data-read-only])'),'The editable sibling keeps its control');
same(1.0,$dom->evaluate('count(//tr[td[@data-label="Type"]="CAA"][@data-read-only])'),'CAA stays read-only even when configured as an editable type');
same(1,$supportLink($dom),'A mixed inventory links read-only customers to support');
// A type the page can't edit, here TXT, is read-only beside an editable A record when edit actions are present.
$dom=storefrontDom(view('domains::partials.dns-inventory',['section'=>['inventory'=>[...$records,['name'=>'@','type'=>'TXT','address'=>'v=spf1 -all','ttl'=>300]],
    'types'=>['A'],'actions'=>['dns'=>'/domains/1/dns']]])->render());
same([0.0,1.0],[$dom->evaluate('count(//tr[td[@data-label="Type"]="A"][@data-read-only])'),$dom->evaluate('count(//tr[td[@data-label="Type"]="TXT"][@data-read-only])')],
    'Only the type the page cannot edit is read-only');
same(1,$supportLink($dom),'An unsupported record type still links to support');
$nativeSuccessful=true;echo "DNS read-only note passed.\n";
