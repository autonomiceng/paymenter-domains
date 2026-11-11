<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A Read-only DNS record says why it can't be changed and what to do.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
storefrontViews();
$note='Read-only records are shown as the registrar reports them. You can’t change them on this page. Contact support if one needs to change.';
$records=[['name'=>'*','type'=>'A','address'=>'192.0.2.10','ttl'=>3600]];
$html=view('domains::partials.dns-inventory',['section'=>['inventory'=>$records,'actions'=>[]]])->render();
expect(str_contains($html,'Read-only')&&str_contains($html,$note),'A Read-only record explains itself');
$html=view('domains::partials.dns-inventory',['section'=>['inventory'=>$records,'types'=>['A'],'actions'=>['dns'=>'/domains/1/dns']]])->render();
expect(str_contains($html,'Edit above')&&!str_contains($html,$note),'Editable records need no note');
// Missing actions still render a read-only inventory without an undefined-key warning.
$html=view('domains::partials.dns-inventory',['section'=>['inventory'=>$records]])->render();
expect(str_contains($html,$note),'An inventory without edit actions explains read-only access');
// A CAA sibling is always read-only while the A record stays editable.
$dom=storefrontDom(view('domains::partials.dns-inventory',['section'=>['inventory'=>$records,'types'=>['A','CAA'],'actions'=>['dns'=>'/domains/1/dns'],
    'caa'=>[['name'=>'@','value'=>'0 issue "example.test"','ttl'=>3600]]]])->render());
same('Edit above',$dom->evaluate('normalize-space(//tr[td[@data-label="Type"]="A"]/td[@data-label="Access"])'),'The editable sibling keeps its control');
same('Read-only',$dom->evaluate('normalize-space(//tr[td[@data-label="Type"]="CAA"]/td[@data-label="Access"])'),'CAA stays read-only even when configured as an editable type');
expect(str_contains($dom->document->textContent,$note),'A mixed inventory explains its read-only row');
// A type the page can't edit, here TXT, is read-only beside an editable A record when edit actions are present.
$dom=storefrontDom(view('domains::partials.dns-inventory',['section'=>['inventory'=>[...$records,['name'=>'@','type'=>'TXT','address'=>'v=spf1 -all','ttl'=>300]],
    'types'=>['A'],'actions'=>['dns'=>'/domains/1/dns']]])->render());
same(['Edit above','Read-only'],[$dom->evaluate('normalize-space(//tr[td[@data-label="Type"]="A"]/td[@data-label="Access"])'),$dom->evaluate('normalize-space(//tr[td[@data-label="Type"]="TXT"]/td[@data-label="Access"])')],
    'Only the type the page cannot edit is read-only');
expect(str_contains($dom->document->textContent,$note),'A mixed inventory with an unsupported type explains its read-only row');
$nativeSuccessful=true;echo "DNS read-only note passed.\n";
